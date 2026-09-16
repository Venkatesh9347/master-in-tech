<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued delivery for F1 parameterized notifications.
 *
 * Payload is a supported event name plus stable record IDs — never models,
 * views, classes, or callbacks. Unknown events are discarded by
 * NotificationService; stale records are discarded before sending.
 * Delivery exceptions propagate so Laravel retry + failed_jobs apply, and
 * callers only ever dispatch post-commit, so a failure here can never roll
 * back the business operation.
 */
class SendTemplatedMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 20;

    /**
     * @param array<string,mixed> $ref Stable record IDs + immutable snapshot
     */
    public function __construct(
        public string $event,
        public int $userId,
        public array $ref = []
    ) {
        // Dispatch only after the surrounding database transaction commits,
        // so a later rollback can never leave a queued mail behind.
        // (Queueable already declares $afterCommit; assigning here avoids a
        // trait property collision.)
        $this->afterCommit = true;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        NotificationService::sendForJob($this->event, $this->userId, $this->ref);
    }
}
