<?php

namespace App\DomainEvents;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Immutable canonical business event (Phase 9-B).
 *
 * Carries an allow-listed event name plus an internal-ids-only payload.
 * Never carries passwords, OTPs, secrets, tokens, or rendered content —
 * callers are responsible for keeping payloads to the same safe shape as
 * the existing webhook contracts.
 */
final class DomainEvent
{
    public readonly string $id;

    public readonly string $occurredAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $name,
        public readonly array $payload = []
    ) {
        $this->id = (string) Str::uuid();
        $this->occurredAt = CarbonImmutable::now()->toISOString();
    }
}
