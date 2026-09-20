<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\ClassroomMessage;
use App\Models\Course;
use App\Models\LiveClassroomSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DB-1C-4 regression: classroom endpoints accept a numeric session ID or a
 * non-numeric room name as {id}. On PostgreSQL the room name must never
 * reach the bigint id lookups (SQLSTATE 22P02); it resolves through the
 * room_id fallback instead. These tests prove resolution identity (not
 * merely HTTP success): the room path must return the same session — with
 * its content — as the numeric-ID path.
 */
class ClassroomRoomResolutionTest extends TestCase
{
    use RefreshDatabase;

    private LiveClassroomSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        Sanctum::actingAs($admin);

        $course = Course::create([
            'title' => 'Room Resolution Course',
            'slug' => 'room-resolution-course',
            'description' => 'DB-1C-4 fixture course.',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);

        $batch = Batch::create([
            'name' => 'Room Resolution Batch',
            'code' => 'RIT(REG1)BC010626',
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'start_date' => now()->toDateString(),
            'status' => 'ongoing',
        ]);

        $this->session = LiveClassroomSession::create([
            'room_id' => 'mit-room-reg-001',
            'batch_id' => $batch->id,
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'title' => 'Room Resolution Session',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'live',
            'created_by' => $admin->id,
        ]);

        ClassroomMessage::create([
            'live_classroom_session_id' => $this->session->id,
            'user_id' => $admin->id,
            'message' => 'room resolution probe message',
        ]);
    }

    public function test_chat_messages_resolve_session_by_room_name(): void
    {
        $response = $this->getJson('/api/classrooms/mit-room-reg-001/messages');

        $response->assertOk();
        $this->assertTrue(
            collect($response->json('messages'))->contains('message', 'room resolution probe message'),
            'Room-name lookup must resolve the session and return its messages.'
        );
    }

    public function test_moderation_state_resolves_session_by_room_name(): void
    {
        $response = $this->getJson('/api/classrooms/mit-room-reg-001/state');

        $response->assertOk();
        $response->assertJsonPath('session.id', $this->session->id);
        $response->assertJsonPath('session.title', 'Room Resolution Session');
    }

    public function test_numeric_session_id_still_resolves(): void
    {
        $response = $this->getJson("/api/classrooms/{$this->session->id}/state");

        $response->assertOk();
        $response->assertJsonPath('session.id', $this->session->id);
        $response->assertJsonPath('session.title', 'Room Resolution Session');
    }

    public function test_unknown_room_name_returns_not_found(): void
    {
        $this->getJson('/api/classrooms/no-such-room-xyz/state')->assertNotFound();
        $this->getJson('/api/classrooms/no-such-room-xyz/messages')->assertNotFound();
    }
}
