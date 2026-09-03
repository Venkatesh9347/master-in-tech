<?php

namespace App\Services\Video;

use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use App\Services\Video\Drivers\LocalHlsAes128Driver;
use App\Services\Video\Drivers\MuxDriver;
use App\Services\Video\Drivers\VideoDriverInterface;
use Illuminate\Support\Str;

class VideoSecurityManager
{
    /**
     * Resolve the active video security driver.
     */
    public function getDriver(?string $driverName = null): VideoDriverInterface
    {
        $driver = $driverName ?: config('video.default_driver', 'local_hls');

        return match ($driver) {
            'mux' => new MuxDriver(),
            default => new LocalHlsAes128Driver(),
        };
    }

    /**
     * Find or auto-provision a VideoAsset record for a lesson.
     */
    public function findOrCreateAssetForLesson(Lesson $lesson): VideoAsset
    {
        $existing = VideoAsset::where('lesson_id', $lesson->id)->first();
        if ($existing) {
            return $existing;
        }

        $duration = 600; // default 10 minutes
        if ($lesson->duration) {
            if (preg_match('/(\d+)\s*(?:min|m)/i', $lesson->duration, $m)) {
                $duration = (int) $m[1] * 60;
            } elseif (preg_match('/(\d+):(\d+)/', $lesson->duration, $m)) {
                $duration = ((int) $m[1] * 60) + (int) $m[2];
            }
        }

        return VideoAsset::create([
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'title' => $lesson->title,
            'driver' => config('video.default_driver', 'local_hls'),
            'asset_id' => 'vasset_' . Str::uuid()->toString(),
            'playback_id' => 'vplay_' . Str::random(16),
            'status' => 'ready',
            'duration_seconds' => $duration,
            'resolutions' => ['360p', '480p', '720p'],
            'storage_path' => "videos/vasset_{$lesson->id}",
            'metadata' => [
                'aspect_ratio' => '16:9',
                'original_video_url' => $lesson->metadata['video_url'] ?? null,
            ],
        ]);
    }

    /**
     * Authorize video playback and issue token with full registered mobile number watermark.
     */
    public function authorizePlayback(Lesson $lesson, User $user): array
    {
        $asset = $this->findOrCreateAssetForLesson($lesson);
        $driver = $this->getDriver($asset->driver);

        $token = $driver->issuePlaybackToken($asset, $user, [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $playbackUrl = $driver->getPlaybackUrl($asset, $token);

        // Format full registered mobile number for dynamic floating watermark
        $rawPhone = trim($user->phone ?? '');
        $watermarkText = $rawPhone !== '' ? $rawPhone : "+91 9876543210 • {$user->name}";

        return [
            'asset_id' => $asset->asset_id,
            'playback_url' => $playbackUrl,
            'playback_token' => $token,
            'expires_in' => config('video.token_ttl_seconds', 300),
            'duration_seconds' => $asset->duration_seconds,
            'resolutions' => $asset->resolutions ?? ['360p', '480p', '720p'],
            'driver' => $asset->driver,
            'watermark' => [
                'mobile_number' => $watermarkText,
                'user_id' => $user->id,
                'session_id' => Str::random(12),
            ],
        ];
    }
}
