<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscodeVideoAssetJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\VideoAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E1 local video ingest: source upload + transcode status for lessons.
 *
 * Admin and owning tutor only (students are rejected by the tutor
 * middleware before reaching these actions). Sources are stored on the
 * private video disk under server-controlled paths and are never served
 * publicly; playback continues exclusively through the secure HLS routes.
 */
class VideoUploadController extends Controller
{
    /**
     * Source extensions accepted alongside the validated MIME type.
     * Extension is derived from sniffed content, never trusted blindly.
     */
    private const ALLOWED_EXTENSIONS = ['mp4', 'webm', 'mov'];

    /**
     * Upload a source video for a lesson and queue local HLS transcoding.
     */
    public function store(
        Request $request,
        int $courseId,
        int $sectionId,
        int $lessonId
    ): JsonResponse {
        [$course, $lesson] = $this->resolveLesson($request, $courseId, $sectionId, $lessonId);

        $maxKilobytes = max(1, (int) (config('video.upload_max_bytes', 512 * 1024 * 1024) / 1024));

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . $maxKilobytes,
                'mimetypes:video/mp4,video/webm,video/quicktime',
            ],
        ]);

        $file = $request->file('file');

        // Extension allowlist on top of MIME sniffing (defense in depth).
        $extension = strtolower((string) $file->extension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return response()->json([
                'message' => 'Unsupported video format. Allowed formats: mp4, webm, mov.',
            ], 422);
        }

        $disk = (string) config('video.storage_disk', 'local');

        $asset = VideoAsset::where('lesson_id', $lesson->id)->first();
        if (! $asset) {
            $asset = new VideoAsset([
                'lesson_id' => $lesson->id,
                'course_id' => $course->id,
                'title' => $lesson->title,
                'driver' => config('video.default_driver', 'local_hls'),
                'asset_id' => 'vasset_' . Str::uuid()->toString(),
            ]);
        }

        $uploadToken = (string) Str::uuid();

        $asset->fill([
            'title' => $lesson->title,
            'course_id' => $course->id,
            'driver' => config('video.default_driver', 'local_hls'),
            'status' => 'processing',
        ]);
        $asset->metadata = array_merge($asset->metadata ?? [], [
            'source' => 'upload',
            'upload_id' => $uploadToken,
            'original_name' => $file->getClientOriginalName(),
            'processing_error' => null,
        ]);
        $asset->save();

        // Remove any leftover source file from a previous upload attempt so
        // a stale source can never be transcoded instead of the new one.
        foreach (Storage::disk($disk)->files("videos/{$asset->asset_id}") as $existing) {
            if (str_starts_with(basename($existing), 'source.')) {
                Storage::disk($disk)->delete($existing);
            }
        }

        $file->storeAs("videos/{$asset->asset_id}", "source.{$extension}", $disk);

        TranscodeVideoAssetJob::dispatch($asset->id, $uploadToken);

        return response()->json([
            'message' => 'Source video uploaded. Transcoding queued.',
            'asset_id' => $asset->asset_id,
            'status' => $asset->status,
            'driver' => $asset->driver,
        ], 202);
    }

    /**
     * Transcode status for a lesson's video asset (never exposes paths).
     */
    public function show(
        Request $request,
        int $courseId,
        int $sectionId,
        int $lessonId
    ): JsonResponse {
        [$course, $lesson] = $this->resolveLesson($request, $courseId, $sectionId, $lessonId);

        $asset = VideoAsset::where('lesson_id', $lesson->id)->first();
        if (! $asset) {
            return response()->json([
                'message' => 'No video asset exists for this lesson.',
            ], 404);
        }

        return response()->json([
            'asset_id' => $asset->asset_id,
            'status' => $asset->status,
            'error' => $asset->metadata['processing_error'] ?? null,
            'duration_seconds' => $asset->duration_seconds,
            'resolutions' => $asset->resolutions ?? [],
            'driver' => $asset->driver,
            'updated_at' => $asset->updated_at?->toISOString(),
        ]);
    }

    /**
     * Retry a failed transcode with a fresh upload identity (stale jobs
     * referencing the previous attempt can no longer publish).
     */
    public function retry(
        Request $request,
        int $courseId,
        int $sectionId,
        int $lessonId
    ): JsonResponse {
        [$course, $lesson] = $this->resolveLesson($request, $courseId, $sectionId, $lessonId);

        $asset = VideoAsset::where('lesson_id', $lesson->id)->first();
        if (! $asset) {
            return response()->json([
                'message' => 'No video asset exists for this lesson.',
            ], 404);
        }

        if ($asset->status !== 'error') {
            return response()->json([
                'message' => 'Only failed transcodes can be retried.',
            ], 422);
        }

        $disk = (string) config('video.storage_disk', 'local');
        $hasSource = false;
        foreach (Storage::disk($disk)->files("videos/{$asset->asset_id}") as $existing) {
            if (str_starts_with(basename($existing), 'source.')) {
                $hasSource = true;
                break;
            }
        }

        if (! $hasSource) {
            return response()->json([
                'message' => 'Source video is no longer available. Please upload again.',
            ], 422);
        }

        $uploadToken = (string) Str::uuid();
        $asset->status = 'processing';
        $asset->metadata = array_merge($asset->metadata ?? [], [
            'upload_id' => $uploadToken,
            'processing_error' => null,
        ]);
        $asset->save();

        TranscodeVideoAssetJob::dispatch($asset->id, $uploadToken);

        return response()->json([
            'message' => 'Transcoding re-queued.',
            'asset_id' => $asset->asset_id,
            'status' => $asset->status,
        ], 202);
    }

    /**
     * Resolve and authorize the lesson (admin or owning instructor).
     *
     * @return array{0:Course,1:Lesson}
     */
    private function resolveLesson(
        Request $request,
        int $courseId,
        int $sectionId,
        int $lessonId
    ): array {
        $user = $request->user();
        $course = Course::findOrFail($courseId);

        if (! $user->isAdmin() && (int) $course->instructor_id !== (int) $user->id) {
            abort(403, 'Unauthorized. You can only manage video for your own courses.');
        }

        $section = Section::where('course_id', $course->id)->findOrFail($sectionId);
        $lesson = Lesson::where('course_id', $course->id)
            ->where('section_id', $section->id)
            ->findOrFail($lessonId);

        return [$course, $lesson];
    }
}
