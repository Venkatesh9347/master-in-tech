<?php

namespace App\Console\Commands;

use App\Models\StudentLoginOtp;
use App\Models\VideoPlaybackSession;
use App\Services\Infrastructure\RedisHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prunes transient/expired records on a schedule.
 *
 * Single-server coordination: the work is guarded by a distributed lock
 * (Redis-backed when redis cache/queue is configured in production, with a
 * safe single-process fallback otherwise) so that when multiple app servers
 * run the same schedule tick, only one performs the maintenance.
 */
class PruneStalePlaybackSessions extends Command
{
    protected $signature = 'mit:prune-stale-sessions {--chunk=500 : Rows processed per batch}';

    protected $description = 'Delete expired video playback sessions and login OTPs (single-runner safe)';

    public function handle(RedisHealthService $redis): int
    {
        $lockKey = 'locks:prune-stale-sessions';

        if (! $redis->acquireLock($lockKey, 300)) {
            $this->warn('Another scheduler runner already holds the prune lock; skipping this tick.');

            return self::SUCCESS;
        }

        try {
            $this->pruneVideoPlaybackSessions((int) $this->option('chunk'));
            $this->pruneLoginOtps((int) $this->option('chunk'));

            return self::SUCCESS;
        } finally {
            $redis->releaseLock($lockKey);
        }
    }

    private function pruneVideoPlaybackSessions(int $chunk): void
    {
        $count = 0;
        do {
            $ids = VideoPlaybackSession::where('expires_at', '<', now()->subHour())
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $count += VideoPlaybackSession::destroy($ids->all());
        } while (true);

        $this->info("Pruned {$count} expired video playback session(s).");
    }

    private function pruneLoginOtps(int $chunk): void
    {
        $count = 0;
        do {
            $ids = StudentLoginOtp::where('expires_at', '<', now()->subDay())
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $count += StudentLoginOtp::destroy($ids->all());
        } while (true);

        $this->info("Pruned {$count} expired student login OTP(s).");
    }
}
