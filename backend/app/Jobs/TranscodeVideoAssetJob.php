<?php

namespace App\Jobs;

use App\Models\VideoAsset;
use App\Services\Video\Drivers\LocalHlsAes128Driver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * E1 local HLS transcode pipeline (AES-128, NOT DRM).
 *
 * Consumes a privately stored source upload and produces real encrypted
 * MPEG-TS segments consumed by the existing secure playback routes. All
 * external processes run through Symfony Process with argument arrays —
 * user-controlled values never reach a shell.
 *
 * Safety properties:
 * - The asset row is the concurrency guard: only a `processing` asset
 *   whose metadata upload token matches this job may publish.
 * - A cache lock prevents duplicate concurrent processing of one asset.
 * - Output is built in a versioned temporary directory and published
 *   atomically only after validation; failures clean up and mark `error`.
 * - A superseding upload rotates the token, so stale jobs abort before
 *   touching the published namespace. Retry uses a fresh token.
 */
class TranscodeVideoAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Renditions served by the player (quality, width, height, bitrate).
     *
     * @var list<array{0:string,1:int,2:int,3:string}>
     */
    public const RENDITIONS = [
        ['360p', 640, 360, '800k'],
        ['480p', 854, 480, '1400k'],
        ['720p', 1280, 720, '2800k'],
    ];

    public const SEGMENT_SECONDS = 6;

    /**
     * Fixed IV matching the EXT-X-KEY tag emitted by the secure playlist
     * endpoints for local AES-128 assets.
     */
    public const FIXED_IV_HEX = '00000000000000000000000000000001';

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        public int $videoAssetId,
        public string $uploadToken
    ) {
        // Dispatch only after the surrounding database transaction commits.
        // (Queueable already declares $afterCommit; assigning here avoids a
        // trait property collision.)
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $asset = VideoAsset::find($this->videoAssetId);
        if (! $asset || ! $this->isClaimable($asset)) {
            // Deleted, already finished, failed, or superseded: nothing to do.
            return;
        }

        $lock = Cache::lock($this->lockKey($asset), 600);
        if (! $lock->get()) {
            // Another worker holds this asset; retry later.
            $this->release(60);

            return;
        }

        try {
            $this->transcode($asset);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether this job still owns the asset (fresh read, token match).
     */
    private function isClaimable(VideoAsset $asset): bool
    {
        return $asset->status === 'processing'
            && (($asset->metadata['upload_id'] ?? null) === $this->uploadToken);
    }

    private function lockKey(VideoAsset $asset): string
    {
        return "e1-transcode-{$asset->asset_id}";
    }

    private function diskName(): string
    {
        return (string) config('video.storage_disk', 'local');
    }

    private function assetDir(VideoAsset $asset): string
    {
        return "videos/{$asset->asset_id}";
    }

    private function tmpDir(VideoAsset $asset): string
    {
        return $this->assetDir($asset) . '/_tmp_' . substr(str_replace('-', '', $this->uploadToken), 0, 16);
    }

    private function ffmpegBinary(): string
    {
        $configured = (string) config('video.ffmpeg_binary', '');

        return $configured !== '' ? $configured : 'ffmpeg';
    }

    private function ffprobeBinary(): string
    {
        $configured = (string) config('video.ffprobe_binary', '');

        return $configured !== '' ? $configured : 'ffprobe';
    }

    private function fail(VideoAsset $asset, string $tmpDir, string $safeMessage, array $logContext = []): void
    {
        // E1-R1: always clean up this attempt's working directory, but only
        // transition to ERROR when the database row still belongs to this
        // job. A superseding upload rotates the token; a stale failure must
        // never overwrite the newer upload's status, token, or metadata.
        Storage::disk($this->diskName())->deleteDirectory($tmpDir);

        $fresh = $asset->fresh();
        if ($fresh && $this->isClaimable($fresh)) {
            $fresh->status = 'error';
            $fresh->metadata = array_merge($fresh->metadata ?? [], [
                'processing_error' => $safeMessage,
            ]);
            $fresh->save();
        } else {
            $logContext['superseded'] = true;
        }

        Log::error('e1.transcode.failed', array_merge([
            'asset_id' => $asset->asset_id,
            'video_asset_id' => $asset->id,
        ], $logContext));
    }

    private function transcode(VideoAsset $asset): void
    {
        $disk = Storage::disk($this->diskName());
        $assetDir = $this->assetDir($asset);
        $tmpDir = $this->tmpDir($asset);

        // Locate the privately stored source (server-named, never the
        // client filename).
        $sourceRelative = null;
        foreach ($disk->files($assetDir) as $existing) {
            if (str_starts_with(basename($existing), 'source.')) {
                $sourceRelative = $existing;
                break;
            }
        }

        if ($sourceRelative === null) {
            $this->fail($asset, $tmpDir, 'Source video is no longer available.');

            return;
        }

        $sourceAbsolute = $disk->path($sourceRelative);

        // Trustworthy media metadata via ffprobe (argument array only).
        $probe = new Process([
            $this->ffprobeBinary(),
            '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type,width,height',
            '-of', 'json',
            $sourceAbsolute,
        ]);
        $probe->setTimeout(300);
        $probe->run();

        if (! $probe->isSuccessful()) {
            $this->fail($asset, $tmpDir, 'Source video could not be read.', [
                'stage' => 'ffprobe',
                'stderr_tail' => mb_substr($probe->getErrorOutput(), -2000),
            ]);

            return;
        }

        $probeData = json_decode($probe->getOutput(), true);
        $hasVideo = false;
        foreach ((array) ($probeData['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? null) === 'video') {
                $hasVideo = true;
                break;
            }
        }
        $duration = (float) ($probeData['format']['duration'] ?? 0);

        if (! $hasVideo || $duration <= 0) {
            $this->fail($asset, $tmpDir, 'Source video could not be read.', [
                'stage' => 'ffprobe_validate',
            ]);

            return;
        }

        // Encryption key shared with the secure key endpoint, plus a key-info
        // file binding the fixed IV used by playlist tags. The URI embedded
        // here only lands in throwaway manifests (deleted below); players
        // receive per-request tokenized key URIs from the secure endpoint.
        // Local-only pipeline: absolute source paths require the local disk.
        $adapter = method_exists($disk, 'getAdapter') ? $disk->getAdapter() : null;
        if (! $adapter instanceof \League\Flysystem\Local\LocalFilesystemAdapter) {
            $this->fail($asset, $tmpDir, 'Transcoding failed. The source video could not be processed.', [
                'stage' => 'disk',
            ]);

            return;
        }

        (new LocalHlsAes128Driver())->ensureEncryptionKey($asset);
        $keyAbsolute = $disk->path("{$assetDir}/enc.key");
        $keyInfoRelative = "{$tmpDir}/keyinfo";
        $disk->put($keyInfoRelative, implode("\n", [
            url("/api/video-stream/{$asset->asset_id}/key"),
            $keyAbsolute,
            self::FIXED_IV_HEX,
        ]) . "\n");
        $keyInfoAbsolute = $disk->path($keyInfoRelative);

        $disk->makeDirectory($tmpDir);
        $producedResolutions = [];

        try {
            foreach (self::RENDITIONS as [$quality, $width, $height, $bitrate]) {
                $maxrate = ((int) $bitrate) * 2 . 'k';

                $process = new Process([
                    $this->ffmpegBinary(),
                    '-y',
                    '-i', $sourceAbsolute,
                    '-map', '0:v:0',
                    '-map', '0:a?',
                    '-c:v', 'libx264',
                    '-preset', 'veryfast',
                    '-b:v', $bitrate,
                    '-maxrate', $maxrate,
                    '-bufsize', $maxrate,
                    '-vf', "scale={$width}:{$height}",
                    // Force keyframes on segment boundaries so HLS can split
                    // at hls_time even for low-framerate sources.
                    '-force_key_frames', 'expr:gte(t,n_forced*6)',
                    '-c:a', 'aac',
                    '-b:a', '128k',
                    '-ar', '48000',
                    '-f', 'hls',
                    '-hls_time', (string) self::SEGMENT_SECONDS,
                    '-hls_playlist_type', 'vod',
                    '-hls_flags', 'independent_segments',
                    '-hls_segment_type', 'mpegts',
                    '-hls_segment_filename', $disk->path($tmpDir) . DIRECTORY_SEPARATOR . "{$quality}_segment_%03d.ts",
                    '-hls_key_info_file', $keyInfoAbsolute,
                    $disk->path($tmpDir) . DIRECTORY_SEPARATOR . "{$quality}.m3u8",
                ]);
                $process->setTimeout(1500);
                $process->run();

                if (! $process->isSuccessful()) {
                    $this->fail($asset, $tmpDir, 'Transcoding failed. The source video could not be processed.', [
                        'stage' => "ffmpeg_{$quality}",
                        'stderr_tail' => mb_substr($process->getErrorOutput(), -2000),
                    ]);

                    return;
                }

                $segments = array_values(array_filter(
                    $disk->files($tmpDir),
                    fn ($file) => str_starts_with(basename($file), "{$quality}_segment_")
                        && str_ends_with($file, '.ts')
                        && $disk->size($file) > 0
                ));

                if (count($segments) === 0) {
                    $this->fail($asset, $tmpDir, 'Transcoding failed. The source video could not be processed.', [
                        'stage' => "validate_{$quality}",
                    ]);

                    return;
                }

                $producedResolutions[] = $quality;
            }

            // Stale check immediately before publishing: a superseding
            // upload rotates the token, so an older job must never move
            // its files over newer output.
            $fresh = $asset->fresh();
            if (! $fresh || ! $this->isClaimable($fresh)) {
                $disk->deleteDirectory($tmpDir);
                Log::info('e1.transcode.superseded', ['asset_id' => $asset->asset_id]);

                return;
            }

            // Atomic-ish publish: move validated segments into the asset
            // namespace, drop throwaway manifests/key-info, then flip READY
            // as the final state change.
            $publishedNames = [];
            foreach ($disk->files($tmpDir) as $tmpFile) {
                $name = basename($tmpFile);
                if (str_ends_with($name, '.m3u8') || $name === 'keyinfo') {
                    continue;
                }
                $disk->move($tmpFile, "{$assetDir}/{$name}");
                $publishedNames[$name] = true;
            }

            // E1-R2: purge superseded tail segments from a longer previous
            // version (e.g. a shorter replacement leaves old trailing
            // segments unreferenced). Strictly scoped to this asset's
            // directory and the server-generated segment naming convention;
            // playlists, keys, sources, and anything else are untouched.
            foreach ($disk->files($assetDir) as $existing) {
                $base = basename($existing);
                if (! isset($publishedNames[$base])
                    && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*_segment_[0-9]+\.ts$/', $base)) {
                    $disk->delete($existing);
                }
            }

            $disk->deleteDirectory($tmpDir);

            $fresh->duration_seconds = (int) round($duration);
            $fresh->resolutions = $producedResolutions;
            $fresh->storage_path = $assetDir;
            $fresh->status = 'ready';
            $fresh->metadata = array_merge($fresh->metadata ?? [], [
                'processing_error' => null,
            ]);
            $fresh->save();
        } catch (\Throwable $e) {
            $this->fail($asset, $tmpDir, 'Transcoding failed. The source video could not be processed.', [
                'stage' => 'exception',
                'exception' => get_class($e),
            ]);
        }
    }
}
