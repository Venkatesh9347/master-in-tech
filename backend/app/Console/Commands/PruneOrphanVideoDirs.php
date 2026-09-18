<?php

namespace App\Console\Commands;

use App\Models\VideoAsset;
use App\Services\Infrastructure\RedisHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Remove orphaned per-asset HLS directories whose VideoAsset row is gone.
 *
 * Asset rows cascade when their lesson/course is deleted, but the segment
 * directories on disk are left behind. This command reaps exactly those
 * leftovers, on a schedule, following the PruneStalePlaybackSessions pattern.
 *
 * Safety model (P2-3):
 * - Only top-level directories directly under the `videos/` root are ever
 *   considered; nested paths, files, and anything else on the disk are
 *   untouched. Deletion uses the storage API (never manual recursion).
 * - Directory names must match the asset-id shape ([A-Za-z0-9_-]); anything
 *   else is skipped, so unknown layouts are never touched.
 * - A directory is deleted ONLY when no VideoAsset row references its name,
 *   regardless of status — active/processing/ready/error assets are always
 *   kept. There is no special-casing by status to get wrong.
 * - Realpath containment (local driver) plus an explicit symlink refusal
 *   prevent escaping the video root.
 * - A grace period (default 24h on directory mtime) protects in-flight
 *   work: uploads create the row before writing files, and a transcode job
 *   can run at most 30 minutes, so a row-less directory older than the
 *   grace window cannot belong to live processing.
 *
 * Concurrency: TranscodeVideoAssetJob aborts when its row is missing and
 * never recreates rows or writes statuses for unclaimable assets, so a
 * reaped directory cannot be resurrected by a stale queued job; at worst a
 * mid-flight job finishes writing segments into a directory that a later
 * tick reaps once it ages out. Single-runner safe via the shared lock.
 */
class PruneOrphanVideoDirs extends Command
{
    protected $signature = 'mit:prune-orphan-video-dirs
        {--apply : Delete orphan directories. Without --apply, only reports (dry run).}
        {--grace-minutes=1440 : Only delete orphan directories older than this many minutes.}';

    protected $description = 'Delete orphaned per-asset HLS directories with no VideoAsset row (dry run by default)';

    private const ROOT = 'videos';

    public function handle(RedisHealthService $redis): int
    {
        $lockKey = 'locks:prune-orphan-video-dirs';

        if (! $redis->acquireLock($lockKey, 600)) {
            $this->warn('Another scheduler runner already holds the prune lock; skipping this tick.');

            return self::SUCCESS;
        }

        try {
            $apply = (bool) $this->option('apply');
            $graceSeconds = max(0, (int) $this->option('grace-minutes')) * 60;

            $diskName = (string) config('video.storage_disk', 'local');
            $disk = Storage::disk($diskName);

            $counts = [
                'examined' => 0,
                'removed' => 0,
                'kept_row_exists' => 0,
                'kept_recent' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];

            try {
                $entries = $disk->directories(self::ROOT);
            } catch (\Throwable $e) {
                Log::warning('video.prune.failed', ['error' => $e->getMessage()]);

                return self::SUCCESS;
            }

            foreach ($entries as $dir) {
                $counts['examined']++;
                $this->processDirectory($disk, $diskName, (string) $dir, $apply, $graceSeconds, $counts);
            }

            $mode = $apply ? 'apply' : 'dry-run';

            $this->info(sprintf(
                'Orphan video dirs (%s): examined=%d removed=%d kept_row_exists=%d kept_recent=%d skipped=%d failed=%d',
                $mode,
                $counts['examined'],
                $counts['removed'],
                $counts['kept_row_exists'],
                $counts['kept_recent'],
                $counts['skipped'],
                $counts['failed']
            ));

            return self::SUCCESS;
        } finally {
            $redis->releaseLock($lockKey);
        }
    }

    private function processDirectory($disk, string $diskName, string $dir, bool $apply, int $graceSeconds, array &$counts): void
    {
        $name = basename(str_replace('\\', '/', $dir));

        // Direct children with asset-id names only.
        if ($dir !== self::ROOT . '/' . $name || ! preg_match('/^[A-Za-z0-9_-]{1,120}$/', $name)) {
            $counts['skipped']++;

            return;
        }

        if (VideoAsset::where('asset_id', $name)->exists()) {
            $counts['kept_row_exists']++;

            return;
        }

        try {
            if (! $this->isDeletable($disk, $diskName, $dir)) {
                $counts['skipped']++;
                Log::warning('video.prune.skipped', ['directory' => $dir]);

                return;
            }

            if (time() - $disk->lastModified($dir) < $graceSeconds) {
                $counts['kept_recent']++;

                return;
            }

            if (! $apply) {
                $counts['removed']++;

                return;
            }

            // Re-check the row immediately before deletion to close the
            // check/delete window against a concurrent re-upload.
            if (VideoAsset::where('asset_id', $name)->exists()) {
                $counts['kept_row_exists']++;

                return;
            }

            $disk->deleteDirectory($dir);
            $counts['removed']++;
        } catch (\Throwable $e) {
            $counts['failed']++;
            Log::warning('video.prune.failed', ['directory' => $dir, 'error' => $e->getMessage()]);
        }
    }

    /**
     * True only when the directory is a real directory strictly inside the
     * video root. Symlinks are always refused, even when they resolve inside
     * (deleting through a link could remove another asset's files).
     */
    private function isDeletable($disk, string $diskName, string $dir): bool
    {
        if (config("filesystems.disks.{$diskName}.driver") !== 'local') {
            // Non-local drivers: top-level + name-pattern guards above are
            // the enforcement; there is no local path to resolve.
            return true;
        }

        try {
            $absolute = $disk->path($dir);
        } catch (\Throwable $e) {
            return false;
        }

        if (@is_link($absolute)) {
            return false;
        }

        $rootReal = realpath($disk->path(self::ROOT));
        $targetReal = realpath($absolute);

        if ($rootReal === false || $targetReal === false) {
            // Already gone (or unreadable): nothing to delete.
            return false;
        }

        return $targetReal !== $rootReal
            && str_starts_with($targetReal, $rootReal . DIRECTORY_SEPARATOR);
    }
}
