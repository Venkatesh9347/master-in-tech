<?php

namespace App\DomainEvents;

/**
 * Minimal in-process domain-event bus (Phase 9-B).
 *
 * - Listeners are registered explicitly (see AppServiceProvider::boot) and
 *   invoked synchronously in registration order.
 * - No persistence, no queue, no HTTP: delivery side effects stay with the
 *   consumers (e.g. the webhook listener persists its ledger and queues its
 *   job with afterCommit semantics, exactly as before).
 * - Unknown events have no listeners and are ignored.
 *
 * Instance state (not static) so test application refreshes and queue
 * worker boots each start from exactly one registration pass — listeners
 * can never accumulate across processes or tests.
 */
class DomainEventBus
{
    /**
     * @var array<string, list<callable>>
     */
    private array $listeners = [];

    /**
     * Publish a canonical event to its registered consumers. An explicit
     * event ID may be supplied for scheduler-generated occurrences that
     * must be stable across repeated evaluations.
     */
    public static function record(string $name, array $payload = [], ?string $eventId = null): void
    {
        app(static::class)->dispatch(new DomainEvent($name, $payload, $eventId));
    }

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->listeners[$event->name] ?? [] as $listener) {
            $listener($event);
        }
    }

    /**
     * @return list<string>
     */
    public function registeredEvents(): array
    {
        return array_keys($this->listeners);
    }
}
