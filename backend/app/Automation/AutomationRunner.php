<?php

namespace App\Automation;

use App\DomainEvents\DomainEvent;
use Illuminate\Support\Facades\Log;

/**
 * Claim-check executor for code-defined automation handlers.
 *
 * The claim uses insert-if-absent on the unique (event_id, handler) index:
 * contention never raises, so the surrounding transaction is never aborted
 * (PostgreSQL would abort on a raised unique violation and reject every
 * later statement in the transaction). Concurrent or repeated evaluation of
 * the same bus event converges on exactly one execution. Work runs in the
 * caller's transaction context, so a business rollback also rolls back the
 * claim. Failures are recorded, never thrown into the bus loop — but a
 * failed row stays reclaimable so the same event can retry later.
 */
final class AutomationRunner
{
    /**
     * @param callable(DomainEvent): void $work
     */
    public static function run(
        string $handler,
        DomainEvent $event,
        ?string $entityType,
        ?int $entityId,
        callable $work
    ): void {
        // Atomic insert-if-absent: returns 1 when this worker owns the
        // claim, 0 when the row already exists. Unlike insert-then-catch,
        // contention raises nothing, so no PostgreSQL transaction ever
        // aborts here (SQLSTATE 25P02). Eloquent model events are bypassed,
        // so timestamps are supplied explicitly.
        $now = now();

        $inserted = AutomationExecution::insertOrIgnore([
            'event_id' => $event->id,
            'handler' => $handler,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => AutomationExecution::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            $execution = AutomationExecution::where('event_id', $event->id)
                ->where('handler', $handler)
                ->firstOrFail();
        } else {
            $execution = self::reclaimIfFailed($event, $handler);

            if ($execution === null) {
                // Success/pending (claimed by a live worker) or lost race.
                return;
            }
        }

        try {
            $work();

            $execution->update(['status' => AutomationExecution::STATUS_SUCCESS]);
        } catch (\Throwable $e) {
            $execution->update([
                'status' => AutomationExecution::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('automation.handler.failed', [
                'handler' => $handler,
                'event' => $event->name,
                'event_id' => $event->id,
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * Atomically reclaim a failed execution for retry. The conditional
     * update guarantees only one concurrent worker wins the row; everyone
     * else sees zero affected rows and returns.
     */
    private static function reclaimIfFailed(DomainEvent $event, string $handler): ?AutomationExecution
    {
        $existing = AutomationExecution::where('event_id', $event->id)
            ->where('handler', $handler)
            ->first();

        if ($existing === null || $existing->status !== AutomationExecution::STATUS_FAILED) {
            return null;
        }

        $claimed = AutomationExecution::where('id', $existing->id)
            ->where('status', AutomationExecution::STATUS_FAILED)
            ->update([
                'status' => AutomationExecution::STATUS_PENDING,
                'error' => null,
            ]);

        if ($claimed === 0) {
            return null;
        }

        return $existing->fresh() ?? $existing;
    }
}
