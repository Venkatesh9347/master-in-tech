<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Maintenance (single-server safe)
|--------------------------------------------------------------------------
|
| Each scheduled task is additionally guarded by a distributed lock inside
| its own command (see PruneStalePlaybackSessions), so even if more than one
| web/scheduler node fires the same tick, only a single node executes the
| work. To run the loop in production on ONE process:
|
|     php artisan schedule:work
|
| Or via cron on the deployment host:
|
|     * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
|
*/
Schedule::command('mit:prune-stale-sessions')
    ->dailyAt('03:00')
    ->withoutOverlapping(240)
    ->onOneServer();

// P2 storage hygiene (verify-before-delete, idempotent, lock-guarded):
// legacy class-material files move off the public disk, and orphaned
// per-asset HLS directories (row deleted, segments left behind) are reaped.
Schedule::command('mit:migrate-legacy-materials --apply')
    ->dailyAt('03:15')
    ->withoutOverlapping(240)
    ->onOneServer();
Schedule::command('mit:prune-orphan-video-dirs --apply')
    ->dailyAt('03:45')
    ->withoutOverlapping(240)
    ->onOneServer();

// Phase 9-C2: due CRM follow-up occurrences emit stable followup.due domain
// events every minute. Bounded 10-minute overlap lock: a single pass scans
// pending rows and publishes (no heavy work inside the tick).
Schedule::command('mit:process-due-crm-followups')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
