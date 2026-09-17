<?php

namespace Tests\Feature;

use App\Jobs\TranscodeVideoAssetJob;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * E1 real local video upload + FFmpeg HLS transcoding.
 *
 * Real FFmpeg-generated segments flow through the existing secure playback
 * routes; tests that need the transcoder binary skip when it is absent.
 */
class E1VideoPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tutor;

    private User $student;

    private Course $course;

    private Section $section;

    private Lesson $lesson;

    /** @var list<string> asset dirs created on the real disk */
    private array $assetDirs = [];

    private static ?string $fixturePath = null;

    /** @var list<string> */
    private static array $extraFixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->tutor = User::factory()->create(['role' => 'tutor']);
        $this->student = User::factory()->create(['role' => 'student']);

        $this->course = Course::create([
            'title' => 'E1 Video Course',
            'slug' => 'e1-video-course-' . Str::random(5),
            'description' => 'E1 pipeline course.',
            'category' => 'Engineering',
            'instructor' => $this->tutor->name,
            'instructor_id' => $this->tutor->id,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'price' => 100,
            'is_published' => true,
        ]);

        $this->section = Section::create([
            'course_id' => $this->course->id,
            'title' => 'Module',
            'slug' => 'module-' . Str::random(4),
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $this->lesson = Lesson::create([
            'course_id' => $this->course->id,
            'section_id' => $this->section->id,
            'title' => 'Video Lesson',
            'slug' => 'video-lesson-' . Str::random(4),
            'type' => 'video',
            'duration' => '10 min',
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->assetDirs as $dir) {
            Storage::disk('local')->deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixturePath !== null && is_file(self::$fixturePath)) {
            @unlink(self::$fixturePath);
            @unlink(self::$fixturePath . '.tsbuild');
            self::$fixturePath = null;
        }

        foreach (self::$extraFixtures as $extra) {
            @unlink($extra);
        }
        self::$extraFixtures = [];

        parent::tearDownAfterClass();
    }

    private function videoUrl(): string
    {
        return "/api/courses/{$this->course->id}/sections/{$this->section->id}/lessons/{$this->lesson->id}/video";
    }

    /**
     * Resolve real transcoder binaries the way a deployment would: explicit
     * config first, then well-known install locations, then PATH. Configures
     * the pipeline to use them (mirrors setting FFMPEG_BINARY/FFPROBE_BINARY
     * in the environment). Returns null when unavailable.
     *
     * @return array{0:string,1:string}|null [ffmpeg, ffprobe]
     */
    private function transcoderBinaries(): ?array
    {
        $configuredFfmpeg = (string) config('video.ffmpeg_binary', '');
        $configuredFfprobe = (string) config('video.ffprobe_binary', '');

        $ffmpeg = $this->resolveBinary($configuredFfmpeg, [
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            'ffmpeg',
        ]);
        $ffprobe = $this->resolveBinary($configuredFfprobe, [
            'C:\\ffmpeg\\bin\\ffprobe.exe',
            '/usr/bin/ffprobe',
            '/usr/local/bin/ffprobe',
            'ffprobe',
        ]);

        if ($ffmpeg === null || $ffprobe === null) {
            return null;
        }

        config([
            'video.ffmpeg_binary' => $ffmpeg,
            'video.ffprobe_binary' => $ffprobe,
        ]);

        return [$ffmpeg, $ffprobe];
    }

    private function resolveBinary(string $configured, array $candidates): ?string
    {
        foreach (array_filter([$configured !== '' ? $configured : null, ...$candidates]) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }

            $probe = new Process([$candidate, '-version']);
            $probe->setTimeout(30);
            try {
                $probe->run();
            } catch (\Throwable) {
                continue;
            }

            if ($probe->isSuccessful()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Deterministic 2s A/V fixture generated with real FFmpeg (once).
     */
    /**
     * Require real transcoder binaries (configures the pipeline); skips the
     * calling test when they are unavailable.
     */
    private function requireTranscoder(): void
    {
        if ($this->transcoderBinaries() === null) {
            $this->markTestSkipped('FFmpeg/ffprobe binaries unavailable.');
        }
    }

    private function fixturePath(int $seconds = 2): string
    {
        if ($seconds === 2 && self::$fixturePath !== null && is_file(self::$fixturePath)) {
            return self::$fixturePath;
        }

        $binaries = $this->transcoderBinaries();
        if ($binaries === null) {
            $this->markTestSkipped('FFmpeg/ffprobe binaries unavailable.');
        }
        [$ffmpeg] = $binaries;

        $suffix = $seconds === 2 ? '' : "_{$seconds}s";
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'e1_fixture_' . getmypid() . $suffix . '.mp4';
        $process = new Process([
            $ffmpeg,
            '-y',
            '-f', 'lavfi', '-i', "testsrc=duration={$seconds}:size=320x240:rate=10",
            '-f', 'lavfi', '-i', "sine=frequency=1000:duration={$seconds}",
            '-pix_fmt', 'yuv420p',
            '-c:v', 'libx264',
            '-c:a', 'aac',
            '-shortest',
            $path,
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path) || filesize($path) === 0) {
            $this->markTestSkipped('FFmpeg fixture generation failed.');
        }

        if ($seconds === 2) {
            self::$fixturePath = $path;
        } else {
            self::$extraFixtures[] = $path;
        }

        return $path;
    }

    private function fixtureUpload(string $clientName = 'lecture.mp4', int $seconds = 2): UploadedFile
    {
        return new UploadedFile(
            $this->fixturePath($seconds),
            $clientName,
            'video/mp4',
            null,
            true
        );
    }

    private function trackAsset(string $assetId): void
    {
        $this->assetDirs[] = "videos/{$assetId}";
    }

    private function uploadAs(User $user, ?UploadedFile $file = null, int $seconds = 2)
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->videoUrl(), [
            'file' => $file ?? $this->fixtureUpload('lecture.mp4', $seconds),
        ]);
    }

    private function runJob(VideoAsset $asset, string $token): void
    {
        (new TranscodeVideoAssetJob($asset->id, $token))->handle();
    }

    private function assetToken(VideoAsset $asset): string
    {
        return (string) ($asset->fresh()->metadata['upload_id'] ?? '');
    }

    // -----------------------------------------------------------------
    // Upload validation + authorization
    // -----------------------------------------------------------------

    public function test_valid_upload_accepted_and_queued(): void
    {
        Queue::fake();

        $response = $this->uploadAs($this->tutor);
        $response->assertStatus(202)
            ->assertJsonPath('status', 'processing')
            ->assertJsonStructure(['asset_id', 'status', 'driver']);

        $assetId = $response->json('asset_id');
        $this->trackAsset($assetId);

        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $this->assertSame('processing', $asset->status);
        $this->assertNotEmpty($asset->metadata['upload_id']);

        // Source stored privately, never on the public disk.
        $this->assertTrue(
            (bool) count(Storage::disk('local')->files("videos/{$assetId}")),
            'Source file must exist on the private disk.'
        );
        $this->assertEmpty(Storage::disk('public')->files("videos/{$assetId}"));

        // Job dispatched after commit with the matching upload identity.
        Queue::assertPushed(TranscodeVideoAssetJob::class, 1);
        Queue::assertPushed(TranscodeVideoAssetJob::class, function ($job) use ($asset) {
            return $job->videoAssetId === $asset->id
                && $job->uploadToken === $asset->metadata['upload_id'];
        });

        // No filesystem paths leak in the response.
        $this->assertArrayNotHasKey('file_path', $response->json());
        $this->assertArrayNotHasKey('storage_path', $response->json());
    }

    public function test_unsupported_extension_rejected(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->create('lecture.avi', 500, 'video/x-msvideo');

        $this->uploadAs($this->tutor, $file)->assertStatus(422);
        $this->assertSame(0, VideoAsset::where('lesson_id', $this->lesson->id)->count());
        Queue::assertNotPushed(TranscodeVideoAssetJob::class);
    }

    public function test_invalid_mime_rejected(): void
    {
        Queue::fake();

        // Text bytes wearing an mp4 name: content sniffing must reject it.
        $file = UploadedFile::fake()->create('lecture.mp4', 500, 'text/plain');

        $this->uploadAs($this->tutor, $file)->assertStatus(422);
        $this->assertSame(0, VideoAsset::where('lesson_id', $this->lesson->id)->count());
    }

    public function test_oversized_source_rejected(): void
    {
        config(['video.upload_max_bytes' => 1024]);

        $this->uploadAs($this->tutor)->assertStatus(422);
        $this->assertSame(0, VideoAsset::where('lesson_id', $this->lesson->id)->count());
    }

    public function test_traversal_filename_cannot_escape_asset_directory(): void
    {
        Queue::fake();

        $evil = new UploadedFile(
            $this->fixturePath(),
            '../../evil.mp4',
            'video/mp4',
            null,
            true
        );

        $response = $this->uploadAs($this->tutor, $evil);
        $response->assertStatus(202);

        $assetId = $response->json('asset_id');
        $this->trackAsset($assetId);

        $files = Storage::disk('local')->files("videos/{$assetId}");
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertStringStartsWith("videos/{$assetId}/", $file);
            $this->assertStringNotContainsString('..', $file);
        }
        $this->assertStringStartsWith('source.', basename($files[0]));
    }

    public function test_student_cannot_upload(): void
    {
        $this->uploadAs($this->student)->assertStatus(403);
        $this->assertSame(0, VideoAsset::where('lesson_id', $this->lesson->id)->count());
    }

    public function test_non_owning_tutor_cannot_upload(): void
    {
        $otherTutor = User::factory()->create(['role' => 'tutor']);

        $this->uploadAs($otherTutor)->assertStatus(403);
        $this->assertSame(0, VideoAsset::where('lesson_id', $this->lesson->id)->count());
    }

    public function test_admin_can_upload(): void
    {
        Queue::fake();

        $response = $this->uploadAs($this->admin);
        $response->assertStatus(202);
        $this->trackAsset($response->json('asset_id'));
    }

    // -----------------------------------------------------------------
    // Status endpoint + playback gating
    // -----------------------------------------------------------------

    public function test_status_endpoint_reports_processing_without_paths(): void
    {
        Queue::fake();

        $assetId = $this->uploadAs($this->tutor)->json('asset_id');
        $this->trackAsset($assetId);

        $response = $this->actingAs($this->tutor, 'sanctum')->getJson($this->videoUrl());
        $response->assertStatus(200)
            ->assertJsonPath('asset_id', $assetId)
            ->assertJsonPath('status', 'processing');

        $this->assertArrayNotHasKey('file_path', $response->json());
        $this->assertArrayNotHasKey('storage_path', $response->json());
    }

    public function test_processing_asset_cannot_be_played(): void
    {
        Queue::fake();

        $this->uploadAs($this->tutor)->assertStatus(202);

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth")
            ->assertStatus(403)
            ->assertJsonPath('message', 'This video is still being processed. Please try again later.');
    }

    // -----------------------------------------------------------------
    // Transcode execution (real FFmpeg)
    // -----------------------------------------------------------------

    public function test_concurrent_claim_runs_only_once(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $assetId = $this->uploadAs($this->tutor)->json('asset_id');
        $this->trackAsset($assetId);
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();

        $lock = Cache::lock("e1-transcode-{$assetId}", 600);
        $this->assertTrue($lock->acquire());

        try {
            $this->runJob($asset, $this->assetToken($asset));
        } finally {
            $lock->release();
        }

        $this->assertSame('processing', $asset->fresh()->status);
        $published = array_values(array_filter(
            Storage::disk('local')->files("videos/{$assetId}"),
            fn ($file) => str_contains(basename($file), '_segment_')
        ));
        $this->assertEmpty($published, 'Contended job must not publish segments.');
    }

    public function test_successful_transcode_produces_real_hls_and_ready(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $assetId = $this->uploadAs($this->tutor)->json('asset_id');
        $this->trackAsset($assetId);
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();

        $this->runJob($asset, $this->assetToken($asset));

        $asset = $asset->fresh();
        $this->assertSame('ready', $asset->status);
        $this->assertGreaterThan(0, $asset->duration_seconds);
        $this->assertSame(['360p', '480p', '720p'], $asset->resolutions);
        $this->assertNull($asset->metadata['processing_error']);

        $disk = Storage::disk('local');
        foreach (['360p', '480p', '720p'] as $quality) {
            $segments = array_values(array_filter(
                $disk->files("videos/{$assetId}"),
                fn ($file) => str_starts_with(basename($file), "{$quality}_segment_")
            ));
            $this->assertNotEmpty($segments, "Expected real segments for {$quality}.");
            foreach ($segments as $segment) {
                $this->assertGreaterThan(0, $disk->size($segment));
            }
        }

        // AES-128 key material exists where the secure key endpoint expects it.
        $this->assertTrue($disk->exists("videos/{$assetId}/enc.key"));
        $this->assertSame(16, strlen($disk->get("videos/{$assetId}/enc.key")));

        // No throwaway manifests or key-info files leak into the namespace.
        foreach ($disk->files("videos/{$assetId}") as $file) {
            $this->assertStringNotContainsString('_tmp_', $file);
            $this->assertFalse(basename($file) === 'keyinfo');
        }
    }

    public function test_secure_playback_serves_real_segments(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $assetId = $this->uploadAs($this->tutor)->json('asset_id');
        $this->trackAsset($assetId);
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $this->runJob($asset, $this->assetToken($asset));

        $auth = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $token = $auth->json('session.playback_token');

        $segment = $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.ts?token=" . urlencode($token));
        $segment->assertStatus(200);
        $body = $segment->getContent();
        $this->assertNotEmpty($body);
        $this->assertStringNotContainsString('ENC_TS_CHUNK_', $body);

        $key = $this->get("/api/video-stream/{$assetId}/key?token=" . urlencode($token));
        $key->assertStatus(200);
        $this->assertSame(16, strlen($key->getContent()));
    }

    public function test_error_asset_cannot_be_played(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $this->uploadAs($this->tutor)->assertStatus(202);
        $asset = VideoAsset::where('lesson_id', $this->lesson->id)->firstOrFail();
        $this->trackAsset($asset->asset_id);

        // Corrupt the source so FFmpeg genuinely fails.
        Storage::disk('local')->put("videos/{$asset->asset_id}/corrupt.bin", 'not-video');
        Storage::disk('local')->delete(
            array_filter(
                Storage::disk('local')->files("videos/{$asset->asset_id}"),
                fn ($file) => str_starts_with(basename($file), 'source.')
            )
        );
        Storage::disk('local')->move(
            "videos/{$asset->asset_id}/corrupt.bin",
            "videos/{$asset->asset_id}/source.mp4"
        );

        $this->runJob($asset, $this->assetToken($asset));

        $asset = $asset->fresh();
        $this->assertSame('error', $asset->status);
        $this->assertNotEmpty($asset->metadata['processing_error']);

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth")
            ->assertStatus(403);
    }

    public function test_ffmpeg_failure_never_ready_cleans_tmp_and_hides_stderr(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $this->uploadAs($this->tutor)->assertStatus(202);
        $asset = VideoAsset::where('lesson_id', $this->lesson->id)->firstOrFail();
        $this->trackAsset($asset->asset_id);

        Storage::disk('local')->put("videos/{$asset->asset_id}/source.mp4", 'garbage-bytes-not-video');

        $this->runJob($asset, $this->assetToken($asset));

        $asset = $asset->fresh();
        $this->assertSame('error', $asset->status);
        $this->assertNotSame('ready', $asset->status);

        // Temporary working directories are removed.
        foreach (Storage::disk('local')->directories("videos/{$asset->asset_id}") as $dir) {
            $this->assertStringNotContainsString('_tmp_', $dir);
        }

        // API error is the safe message, never raw FFmpeg output.
        $status = $this->actingAs($this->tutor, 'sanctum')->getJson($this->videoUrl());
        $status->assertStatus(200);
        $error = (string) $status->json('error');
        $this->assertNotEmpty($error);
        $this->assertStringNotContainsString('ffmpeg', strtolower($error));
        $this->assertStringNotContainsString(':\\', $error);
        $this->assertStringNotContainsString('conversion failed', strtolower($error));
    }

    public function test_retry_is_safe_and_recovers(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $this->uploadAs($this->tutor)->assertStatus(202);
        $asset = VideoAsset::where('lesson_id', $this->lesson->id)->firstOrFail();
        $this->trackAsset($asset->asset_id);

        // Force an error with the source present (simulates a timed-out run).
        $asset->status = 'error';
        $asset->metadata = array_merge($asset->metadata ?? [], [
            'processing_error' => 'Transcoding failed. The source video could not be processed.',
        ]);
        $asset->save();

        $retry = $this->actingAs($this->tutor, 'sanctum')->postJson($this->videoUrl() . '/retry');
        $retry->assertStatus(202)->assertJsonPath('status', 'processing');

        $this->runJob($asset, $this->assetToken($asset));

        $asset = $asset->fresh();
        $this->assertSame('ready', $asset->status);
        $this->assertNull($asset->metadata['processing_error']);
    }

    public function test_stale_job_cannot_publish_over_newer_upload(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $first = $this->uploadAs($this->tutor)->json('asset_id');
        $this->trackAsset($first);
        $staleToken = (string) VideoAsset::where('asset_id', $first)->firstOrFail()->metadata['upload_id'];

        // Second upload supersedes the first (same asset row, new token).
        $second = $this->uploadAs($this->tutor)->json('asset_id');
        $this->assertSame($first, $second);
        $freshToken = $this->assetToken(VideoAsset::where('asset_id', $first)->firstOrFail());
        $this->assertNotSame($staleToken, $freshToken);

        // Stale job aborts: still processing, nothing published.
        $asset = VideoAsset::where('asset_id', $first)->firstOrFail();
        $this->runJob($asset, $staleToken);

        $asset = $asset->fresh();
        $this->assertSame('processing', $asset->status);
        $this->assertFalse(Storage::disk('local')->exists("videos/{$first}/720p_segment_000.ts"));

        // Current job publishes normally.
        $this->runJob($asset, $freshToken);
        $this->assertSame('ready', $asset->fresh()->status);
    }

    public function test_stale_failure_cannot_corrupt_newer_upload(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        // Upload A, then supersede it with upload B (same asset, new token).
        $assetId = $this->uploadAs($this->tutor)->json('asset_id');
        $this->trackAsset($assetId);
        $staleToken = (string) VideoAsset::where('asset_id', $assetId)->firstOrFail()->metadata['upload_id'];

        $this->uploadAs($this->tutor)->assertStatus(202);
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $freshToken = (string) $asset->metadata['upload_id'];
        $this->assertNotSame($staleToken, $freshToken);

        // Drive the stale job's actual failure path with a stale instance:
        // fail() is only reachable mid-run, so invoke it directly against a
        // snapshot carrying the superseded identity.
        $stale = VideoAsset::find($asset->id);
        $stale->metadata = array_merge($stale->metadata ?? [], ['upload_id' => $staleToken]);

        $disk = Storage::disk('local');
        $staleTmp = "videos/{$assetId}/_tmp_" . substr(str_replace('-', '', $staleToken), 0, 16);
        $disk->put("{$staleTmp}/marker.tmp", 'stale-attempt');

        $job = new TranscodeVideoAssetJob($asset->id, $staleToken);
        $method = new \ReflectionMethod(TranscodeVideoAssetJob::class, 'fail');
        $method->setAccessible(true);
        $method->invoke($job, $stale, $staleTmp, 'Transcoding failed. The source video could not be processed.');

        // Newer upload B is untouched: still processing under its own token
        // with no error, while the stale attempt's temp dir was cleaned.
        $row = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $this->assertSame('processing', $row->status);
        $this->assertSame($freshToken, $row->metadata['upload_id']);
        $this->assertNull($row->metadata['processing_error']);
        $this->assertFalse($disk->exists($staleTmp));
    }

    public function test_shorter_replacement_purges_stale_tail_segments(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        // Longer version first: two segments per rendition.
        $assetId = $this->uploadAs($this->tutor, null, 8)->json('asset_id');
        $this->trackAsset($assetId);
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $this->runJob($asset, $this->assetToken($asset));
        $this->assertSame('ready', $asset->fresh()->status);

        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists("videos/{$assetId}/720p_segment_001.ts"));

        // Shorter replacement completes: tail segment must be purged while
        // current output, key, and source remain intact and playable.
        $this->uploadAs($this->tutor, null, 2)->assertStatus(202);
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $this->runJob($asset, $this->assetToken($asset));
        $this->assertSame('ready', $asset->fresh()->status);

        $this->assertFalse(
            $disk->exists("videos/{$assetId}/720p_segment_001.ts"),
            'Superseded tail segments must be purged on publish.'
        );

        foreach (['360p', '480p', '720p'] as $quality) {
            $this->assertTrue($disk->exists("videos/{$assetId}/{$quality}_segment_000.ts"));
            $this->assertGreaterThan(0, $disk->size("videos/{$assetId}/{$quality}_segment_000.ts"));
        }
        $this->assertTrue($disk->exists("videos/{$assetId}/enc.key"));
        $this->assertTrue($disk->exists("videos/{$assetId}/source.mp4"));

        $auth = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $token = $auth->json('session.playback_token');
        $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.ts?token=" . urlencode($token))
            ->assertStatus(200);
    }

    public function test_segment_endpoint_rejects_source_filename(): void
    {
        $this->requireTranscoder();
        Queue::fake();

        $assetId = $this->uploadAs($this->tutor)->json('asset_id');
        $this->trackAsset($assetId);
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        $this->runJob($asset, $this->assetToken($asset));

        $auth = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $token = $auth->json('session.playback_token');

        $this->get("/api/video-stream/{$assetId}/segments/source.mp4?token=" . urlencode($token))
            ->assertStatus(404);
        $this->assertFalse(Storage::disk('public')->exists("videos/{$assetId}/source.mp4"));
    }
}
