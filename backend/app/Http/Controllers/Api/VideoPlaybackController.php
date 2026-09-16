<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use App\Services\Video\Drivers\LocalHlsAes128Driver;
use App\Services\Video\VideoSecurityManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VideoPlaybackController extends Controller
{
    /**
     * Authorize video playback and issue short-lived token.
     */
    public function authorizeLessonPlayback(
        Request $request,
        int $courseId,
        int $lessonId,
        VideoSecurityManager $videoManager
    ): JsonResponse {
        $user = $request->user();
        $course = Course::findOrFail($courseId);
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        // 1. Verify Course & Enrollment Authorization
        $this->authorizeStudentOrInstructor($user, $course);

        // 2. Verify Lesson is Published
        if (! $lesson->is_published && ! $user->isAdmin() && (int) $course->instructor_id !== (int) $user->id) {
            return response()->json([
                'message' => 'This lesson is currently unpublished and unavailable for streaming.',
            ], 403);
        }

        // 3. Verify Course is Published/Active
        if (! $course->is_published && ! $user->isAdmin() && (int) $course->instructor_id !== (int) $user->id) {
            return response()->json([
                'message' => 'This course is currently not published.',
            ], 403);
        }

        // 4. Issue Short-Lived Playback Authorization & Watermark
        $playbackSession = $videoManager->authorizePlayback($lesson, $user);

        return response()->json([
            'message' => 'Playback authorized.',
            'lesson_id' => $lesson->id,
            'title' => $lesson->title,
            'session' => $playbackSession,
        ]);
    }

    /**
     * Serve Master Adaptive HLS Playlist (.m3u8).
     */
    public function getMasterPlaylist(
        Request $request,
        string $assetId,
        VideoSecurityManager $videoManager
    ): Response {
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $token = $request->query('token', '');

        $driver = $videoManager->getDriver($asset->driver);
        if (! $driver->verifyPlaybackToken($asset, $token)) {
            return response('Unauthorized video stream token.', 403);
        }

        if ($driver instanceof LocalHlsAes128Driver) {
            $content = $driver->generateMasterPlaylist($asset, $token);

            return response($content, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        return response('Invalid driver.', 400);
    }

    /**
     * Serve Variant Quality Playlist (.m3u8) with AES-128 Key Tag.
     */
    public function getVariantPlaylist(
        Request $request,
        string $assetId,
        string $quality,
        VideoSecurityManager $videoManager
    ): Response {
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $token = $request->query('token', '');

        $driver = $videoManager->getDriver($asset->driver);
        if (! $driver->verifyPlaybackToken($asset, $token)) {
            return response('Unauthorized video stream token.', 403);
        }

        if ($driver instanceof LocalHlsAes128Driver) {
            $content = $driver->generateVariantPlaylist($asset, $quality, $token);

            return response($content, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        return response('Invalid driver.', 400);
    }

    /**
     * Deliver 16-Byte Binary AES-128 Decryption Key.
     */
    public function getKey(
        Request $request,
        string $assetId,
        VideoSecurityManager $videoManager
    ): Response {
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $token = $request->query('token', '');

        $driver = $videoManager->getDriver($asset->driver);

        // Verify signed token/session/asset binding first (existing behavior).
        if (! $driver->verifyPlaybackToken($asset, $token)) {
            return response('Unauthorized key request or expired session.', 403);
        }

        // S-03 defense-in-depth: verify current enrollment/access before
        // releasing the AES-128 key. Uses the verified token identity and the
        // asset's authoritative course; no client-supplied IDs are trusted.
        if (! $this->hasCurrentKeyAccess($asset, $token)) {
            return response('Unauthorized key request or expired session.', 403);
        }

        $key = $driver->getDecryptionKey($asset, $token);

        if ($key === null) {
            return response('Unauthorized key request or expired session.', 403);
        }

        return response($key, 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, no-cache, private, must-revalidate',
            ...$this->corsHeaders($request),
        ]);
    }

    /**
     * Serve Encrypted Media Segment (.ts).
     */
    public function getSegment(
        Request $request,
        string $assetId,
        string $segment,
        VideoSecurityManager $videoManager
    ): Response {
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $token = $request->query('token', '');

        $driver = $videoManager->getDriver($asset->driver);
        if (! $driver->verifyPlaybackToken($asset, $token)) {
            return response('Unauthorized segment request.', 403);
        }

        if (! $driver instanceof LocalHlsAes128Driver) {
            return response('Invalid driver.', 400);
        }

        // Pull the encrypted MPEG-TS bytes from the driver (local disk or
        // S3-compatible object store). Null means the object is unavailable.
        $payload = $driver->readSegment($asset, $segment, $token);
        if ($payload === null) {
            return response('Segment unavailable or unauthorized.', 404);
        }

        return response($payload, 200, [
            'Content-Type' => 'video/MP2T',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ...$this->corsHeaders($request),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Private Authorization Guard
    |--------------------------------------------------------------------------
    */

    /**
     * Build cross-origin headers restricted to configured frontend origins.
     *
     * Returns an empty array (no CORS header) when the request Origin is not
     * explicitly allowed, blocking cross-origin reads of the media stream.
     */
    private function corsHeaders(Request $request): array
    {
        $origin = $request->headers->get('Origin');
        if (! $origin) {
            return [];
        }

        $allowed = config('cors.allowed_origins', []);
        foreach ((array) $allowed as $candidate) {
            if (rtrim((string) $candidate, '/') === rtrim($origin, '/')) {
                return ['Access-Control-Allow-Origin' => $origin];
            }
        }

        return [];
    }

    private function authorizeStudentOrInstructor($user, Course $course): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->role === 'tutor' && (int) $course->instructor_id === (int) $user->id) {
            return;
        }

        // B3 pay-before-classroom: only active/completed grant streaming access.
        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        if (! $isEnrolled) {
            abort(403, 'Course Access Required. You must have an active enrollment in this course to stream video lessons.');
        }
    }

    /**
     * S-03 defense-in-depth: verify current access before AES-128 key release.
     *
     * Identity comes from the already-verified signed token payload and the
     * asset's authoritative course_id; client-supplied IDs are never trusted.
     * Preserves admin + owning-tutor bypass; students require active/completed.
     */
    private function hasCurrentKeyAccess(VideoAsset $asset, string $token): bool
    {
        if (! str_contains($token, '.')) {
            return false;
        }

        [$encodedPayload] = explode('.', $token, 2);
        $payload = json_decode(base64_decode($encodedPayload), true);
        if (! is_array($payload) || empty($payload['user_id'])) {
            return false;
        }

        $user = User::find($payload['user_id']);
        if (! $user) {
            return false;
        }

        $course = Course::find($asset->course_id);
        if (! $course) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->role === 'tutor' && (int) $course->instructor_id === (int) $user->id) {
            return true;
        }

        return CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }
}
