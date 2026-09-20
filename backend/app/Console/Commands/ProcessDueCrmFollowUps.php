<?php

namespace App\Console\Commands;

use App\DomainEvents\DomainEventBus;
use App\Models\CrmFollowUp;
use Illuminate\Console\Command;

class ProcessDueCrmFollowUps extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mit:process-due-crm-followups';

    /**
     * @var string
     */
    protected $description = 'Publish followup.due domain events for actionable (pending, due) CRM follow-ups.';

    public function handle(): int
    {
        // Authoritative scheduling field is crm_follow_ups.scheduled_at.
        // Completed/cancelled rows are never selected; follow-up status is
        // never mutated here (pending + past-due stays the overdue signal).
        $due = CrmFollowUp::where('status', CrmFollowUp::STATUS_PENDING)
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($due as $followUp) {
            DomainEventBus::record(
                'followup.due',
                [
                    'follow_up_id' => (int) $followUp->id,
                    'enquiry_id' => (int) $followUp->enquiry_id,
                    'assigned_to' => $followUp->assigned_to === null ? null : (int) $followUp->assigned_to,
                    'scheduled_at' => $followUp->scheduled_at?->toISOString(),
                ],
                self::eventIdFor($followUp)
            );
        }

        $this->info("Published followup.due for {$due->count()} follow-up(s).");

        return self::SUCCESS;
    }

    /**
     * Deterministic occurrence identity: the same follow-up at the same
     * scheduled instant always yields the same event ID (stable across
     * repeated scheduler runs, so redelivery converges); rescheduling
     * yields a different ID because it is a different occurrence.
     *
     * The canonical instant always carries microseconds (000000 when the
     * database stores second precision), so it round-trips byte-identical
     * on every engine. Full SHA-256 hex fits event_id string(64) exactly.
     */
    public static function eventIdFor(CrmFollowUp $followUp): string
    {
        $instant = $followUp->scheduled_at?->format('Y-m-d H:i:s.u') ?? '';

        return hash('sha256', "followup.due|{$followUp->id}|{$instant}");
    }
}
