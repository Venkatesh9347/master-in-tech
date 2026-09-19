<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DB-02 regression: GET /api/public/home must return published events and
 * exclude draft/completed/cancelled events (events table is status-based;
 * it has no is_published column).
 */
class PublicHomeEventsStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(string $key, string $status, int $daysFromNow): Event
    {
        return Event::create([
            'title' => "Home Status Event {$key}",
            'slug' => "home-status-event-{$key}",
            'description' => "DB-02 fixture event with status {$status}.",
            'speaker_name' => 'Fixture Speaker',
            'speaker_designation' => 'Fixture Role',
            'event_date' => now()->addDays($daysFromNow)->format('Y-m-d H:i:s'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'duration' => 60,
            'mode' => 'online',
            'status' => $status,
        ]);
    }

    public function test_public_home_returns_only_published_events(): void
    {
        // The published fixture is deliberately the LATEST event_date so the
        // take(3) limit cannot mask a missing status predicate: without the
        // status filter the limit would return the three non-published rows
        // and exclude the published one.
        $draft = $this->makeEvent('draft', 'draft', 1);
        $completed = $this->makeEvent('completed', 'completed', 2);
        $cancelled = $this->makeEvent('cancelled', 'cancelled', 3);
        $published = $this->makeEvent('published', 'published', 4);

        $response = $this->getJson('/api/public/home');

        $response->assertOk();

        $events = $response->json('events');

        $this->assertIsArray($events);
        $this->assertCount(1, $events);

        $ids = collect($events)->pluck('id')->all();

        $this->assertContains($published->id, $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($completed->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }
}
