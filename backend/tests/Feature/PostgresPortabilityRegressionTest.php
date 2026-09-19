<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Event;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\LiveClassroomParticipant;
use App\Models\LiveClassroomSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DB-1C-2 regression: PostgreSQL application portability.
 *
 * 1. Id-or-slug lookups must never compare a non-numeric string against a
 *    bigint id column (SQLSTATE 22P02 on PostgreSQL). Numeric IDs, slugs
 *    (including the slug path that crashed CI), and unknown-identifier 404s
 *    must keep working.
 * 2. Carbon 3 diffInSeconds() returns a float; duration_seconds columns are
 *    integers and Eloquent integer casts apply on read only, so the value
 *    must be normalized to int before persistence.
 */
class PostgresPortabilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(string $slug): Course
    {
        return Course::create([
            'title' => 'Portability ' . $slug,
            'slug' => $slug,
            'description' => 'DB-1C-2 fixture course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'code' => strtoupper(Str::random(3)),
            'status' => 'published',
            'is_published' => true,
            'price' => 100.00,
        ]);
    }

    public function test_course_show_resolves_slug_numeric_id_and_unknown_identifier(): void
    {
        $course = $this->createCourse('portability-lookup-course');

        // Slug path: crashed on PostgreSQL before the fix (22P02).
        $bySlug = $this->getJson("/api/courses/{$course->slug}");
        $bySlug->assertOk();
        $bySlug->assertJsonPath('id', $course->id);

        // Numeric-ID path must keep working.
        $byId = $this->getJson("/api/courses/{$course->id}");
        $byId->assertOk();
        $byId->assertJsonPath('id', $course->id);

        // Unknown identifiers stay 404 (not 500).
        $this->getJson('/api/courses/no-such-course-xyz')->assertNotFound();
    }

    public function test_event_show_resolves_slug_numeric_id_and_unknown_identifier(): void
    {
        $event = Event::create([
            'title' => 'Portability Event',
            'slug' => 'portability-event',
            'description' => 'DB-1C-2 fixture event.',
            'speaker_name' => 'Fixture Speaker',
            'speaker_designation' => 'Fixture Role',
            'event_date' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'duration' => 60,
            'mode' => 'online',
            'status' => 'published',
        ]);

        $bySlug = $this->getJson("/api/events/{$event->slug}");
        $bySlug->assertOk();
        $bySlug->assertJsonPath('id', $event->id);

        $byId = $this->getJson("/api/events/{$event->id}");
        $byId->assertOk();
        $byId->assertJsonPath('id', $event->id);

        $this->getJson('/api/events/no-such-event-xyz')->assertNotFound();
    }

    public function test_class_history_search_accepts_non_numeric_and_numeric_terms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $course = $this->createCourse('portability-history-course');

        Sanctum::actingAs($admin);

        $session = ClassSession::create([
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'title' => 'Portability Search Session',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/4242',
            'meeting_id' => 'TEAMS-PG-42',
            'scheduled_date' => now()->subDays(5)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '15:00',
            'status' => 'completed',
            'created_by' => $admin->id,
        ]);

        // Non-numeric search term: crashed on PostgreSQL before the fix (22P02).
        $byCode = $this->getJson('/api/admin/class-history?search=TEAMS-PG-42');
        $byCode->assertOk();
        $this->assertContains($session->id, collect($byCode->json('history'))->pluck('id')->all());

        // Numeric search term must still match by id.
        $byId = $this->getJson("/api/admin/class-history?search={$session->id}");
        $byId->assertOk();
        $this->assertContains($session->id, collect($byId->json('history'))->pluck('id')->all());
    }

    public function test_record_leave_persists_integer_durations(): void
    {
        $frozenNow = Carbon::parse('2026-06-01 12:00:00');
        Carbon::setTestNow($frozenNow);

        try {
            $tutor = User::factory()->create(['role' => 'tutor']);
            $student = User::factory()->create(['role' => 'student']);
            $course = $this->createCourse('portability-duration-course');

            $liveClass = LiveClass::create([
                'course_id' => $course->id,
                'instructor_id' => $tutor->id,
                'title' => 'Portability Duration Class',
                'class_date' => $frozenNow->toDateString(),
                'start_time' => '10:00',
                'status' => 'live',
            ]);

            // Carbon 3 diffInSeconds() returns a float (89.25 here); the raw
            // persisted value must be the integer 89, not a float. The
            // microsecond-bearing datetime is injected in-memory because
            // Eloquent date serialization truncates microseconds on write,
            // while PostgreSQL timestamps preserve them (hence PG-only 22P02).
            $attendance = LiveClassAttendance::create([
                'live_class_id' => $liveClass->id,
                'user_id' => $student->id,
                'course_id' => $course->id,
                'joined_at' => $frozenNow->copy()->subSeconds(89),
            ]);
            $attendance->setRawAttributes(array_merge($attendance->getAttributes(), [
                'joined_at' => $frozenNow->copy()->subSeconds(89)->subMicroseconds(250000),
            ]), true);
            $attendance->recordLeave();

            $this->assertSame(
                89,
                DB::table('live_class_attendances')->where('id', $attendance->id)->value('duration_seconds')
            );

            $batch = Batch::create([
                'course_id' => $course->id,
                'tutor_id' => $tutor->id,
                'code' => 'RIT(PG1)BC010626',
                'name' => 'Portability Duration Batch',
                'start_date' => $frozenNow->toDateString(),
                'status' => 'active',
            ]);

            $session = LiveClassroomSession::create([
                'room_id' => 'mit-room-pg-duration-001',
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'tutor_id' => $tutor->id,
                'title' => 'Portability Duration Session',
                'scheduled_date' => $frozenNow->toDateString(),
                'start_time' => '10:00',
                'end_time' => '11:00',
                'status' => 'live',
                'created_by' => $tutor->id,
            ]);

            $participant = LiveClassroomParticipant::create([
                'live_classroom_session_id' => $session->id,
                'user_id' => $student->id,
                'role' => 'participant',
                'joined_at' => $frozenNow->copy()->subSeconds(45),
            ]);
            $participant->setRawAttributes(array_merge($participant->getAttributes(), [
                'joined_at' => $frozenNow->copy()->subSeconds(45)->subMicroseconds(500000),
            ]), true);
            $participant->recordLeave();

            $this->assertSame(
                45,
                DB::table('live_classroom_participants')->where('id', $participant->id)->value('duration_seconds')
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
