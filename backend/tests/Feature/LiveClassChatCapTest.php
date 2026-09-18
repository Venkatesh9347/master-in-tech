<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LiveClass;
use App\Models\LiveClassMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P2-2: the legacy live-class chat endpoint serializes at most 150 messages
 * (same bound as the classroom chat endpoint). History is retained;
 * authorization is unchanged.
 */
class LiveClassChatCapTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $tutor;

    private User $student;

    private LiveClass $liveClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->create(['role' => 'tutor']);
        $this->student = User::factory()->create(['role' => 'student']);

        $title = 'Course ' . Str::random(6);

        $this->course = Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Chat cap fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'instructor_id' => $this->tutor->id,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);

        CourseEnrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'progress_percentage' => 10.0,
            'enrolled_at' => now(),
        ]);

        $this->liveClass = LiveClass::create([
            'course_id' => $this->course->id,
            'instructor_id' => $this->tutor->id,
            'title' => 'Capped Chat Class',
            'class_date' => now()->toDateString(),
            'start_time' => '17:00',
            'status' => 'live',
            'is_chat_enabled' => true,
        ]);
    }

    private function seedMessages(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $message = LiveClassMessage::create([
                'live_class_id' => $this->liveClass->id,
                'user_id' => $this->student->id,
                'message' => 'message ' . $i,
            ]);

            // Distinct timestamps so ordering assertions are deterministic.
            $message->forceFill(['created_at' => now()->subMinutes(500 - $i)])->save();
        }
    }

    private function fetch()
    {
        return $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/live-classes/{$this->liveClass->id}/messages");
    }

    public function test_up_to_150_messages_returns_all(): void
    {
        $this->seedMessages(100);

        $response = $this->fetch()->assertOk();

        $this->assertCount(100, $response->json());
    }

    public function test_more_than_150_messages_returns_exactly_150_oldest_first(): void
    {
        $this->seedMessages(160);

        $messages = $this->fetch()->assertOk()->json();

        $this->assertCount(150, $messages);

        $texts = array_column($messages, 'message');
        $this->assertSame('message 0', $texts[0]);
        $this->assertSame('message 149', $texts[149]);

        // Nothing was deleted: the full history is retained.
        $this->assertSame(160, LiveClassMessage::where('live_class_id', $this->liveClass->id)->count());
    }

    public function test_authorization_remains_unchanged(): void
    {
        $this->seedMessages(2);

        // Guest: unauthenticated.
        $this->getJson("/api/live-classes/{$this->liveClass->id}/messages")->assertStatus(401);

        // Enrolled student and tutor keep access.
        $this->fetch()->assertOk();
        $this->actingAs($this->tutor, 'sanctum')
            ->getJson("/api/live-classes/{$this->liveClass->id}/messages")
            ->assertOk();

        // Unenrolled student stays rejected.
        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson("/api/live-classes/{$this->liveClass->id}/messages")
            ->assertStatus(403);
    }
}
