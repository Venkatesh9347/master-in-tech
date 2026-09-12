<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Event;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Event registration integrity + idempotent lesson publish endpoints.
 */
class EventPublishHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'title' => 'Hardening Summit',
            'slug' => 'hardening-summit-' . uniqid(),
            'description' => 'Fixture event.',
            'speaker_name' => 'Fixture Speaker',
            'speaker_designation' => 'Engineer',
            'event_date' => now()->addDays(5)->format('Y-m-d H:i'),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration' => 120,
            'mode' => 'online',
            'status' => 'published',
        ], $overrides));
    }

    public function test_draft_event_rejects_registration(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $event = $this->makeEvent(['status' => 'draft']);

        Sanctum::actingAs($student);
        $this->postJson("/api/events/{$event->id}/register")
            ->assertStatus(400)
            ->assertJson(['message' => 'Registrations are not open for this event']);
    }

    public function test_cancel_is_idempotent_and_never_negative(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $event = $this->makeEvent();

        Sanctum::actingAs($student);
        $this->postJson("/api/events/{$event->id}/register")->assertCreated();
        $this->assertSame(1, $event->fresh()->registered_count);

        $this->deleteJson("/api/events/{$event->id}/register")->assertOk();
        $this->assertSame(0, $event->fresh()->registered_count);

        // Repeat cancel: still OK, counter stays floored at zero.
        $this->deleteJson("/api/events/{$event->id}/register")->assertOk();
        $this->assertSame(0, $event->fresh()->registered_count);
    }

    public function test_publish_unpublish_endpoints_are_idempotent(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        $course = Course::create([
            'title' => 'Publish Fixture', 'slug' => 'publish-fixture-' . uniqid(),
            'description' => 'x', 'instructor' => $tutor->name, 'instructor_id' => $tutor->id,
            'duration' => '1 Week', 'difficulty' => 'Beginner', 'is_published' => true,
        ]);
        $section = Section::create([
            'course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'is_published' => true,
        ]);
        $lesson = Lesson::create([
            'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'L', 'sort_order' => 1, 'type' => 'text', 'is_published' => true,
        ]);
        $url = "/api/courses/{$course->id}/sections/{$section->id}/lessons/{$lesson->id}";

        Sanctum::actingAs($tutor);

        // Unpublish twice: stays draft (previously flipped back to published).
        $this->postJson("{$url}/unpublish")->assertOk();
        $this->assertFalse((bool) $lesson->fresh()->is_published);
        $this->postJson("{$url}/unpublish")->assertOk();
        $this->assertFalse((bool) $lesson->fresh()->is_published);

        // Publish twice: stays published.
        $this->postJson("{$url}/publish")->assertOk();
        $this->assertTrue((bool) $lesson->fresh()->is_published);
        $this->postJson("{$url}/publish")->assertOk();
        $this->assertTrue((bool) $lesson->fresh()->is_published);

        // Toggle still flips exactly once.
        $this->postJson("{$url}/toggle-publish")->assertOk();
        $this->assertFalse((bool) $lesson->fresh()->is_published);
    }
}
