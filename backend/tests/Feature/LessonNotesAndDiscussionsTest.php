<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonDiscussion;
use App\Models\LessonNote;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonNotesAndDiscussionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $studentA;
    protected User $studentB;
    protected User $tutor;
    protected Course $course;
    protected Section $section;
    protected Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studentA = User::factory()->create(['role' => 'student', 'name' => 'Student A']);
        $this->studentB = User::factory()->create(['role' => 'student', 'name' => 'Student B']);
        $this->tutor = User::factory()->create(['role' => 'tutor', 'name' => 'Instructor Sarah']);

        $this->course = Course::create([
            'title' => 'Mastering Cloud Architecture',
            'slug' => 'mastering-cloud-architecture',
            'description' => 'Comprehensive masterclass in cloud architecture.',
            'instructor' => 'Sarah Johnson',
            'is_published' => true,
            'price' => 199,
            'duration' => '12 Weeks',
            'difficulty' => 'Intermediate',
            'category' => 'CLOUD COMPUTING',
        ]);

        $this->section = Section::create([
            'course_id' => $this->course->id,
            'title' => 'Module 1: AWS VPC & Networking',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        $this->lesson = Lesson::create([
            'course_id' => $this->course->id,
            'section_id' => $this->section->id,
            'title' => 'Configuring Subnets and Route Tables',
            'type' => 'video',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        CourseEnrollment::create(['user_id' => $this->studentA->id, 'course_id' => $this->course->id, 'status' => 'active', 'enrolled_at' => now()]);
        CourseEnrollment::create(['user_id' => $this->studentB->id, 'course_id' => $this->course->id, 'status' => 'active', 'enrolled_at' => now()]);
    }

    public function test_student_can_save_and_retrieve_personal_lesson_notes(): void
    {
        $response = $this->actingAs($this->studentA, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/note", [
                'note' => 'CIDR block /24 gives 256 IPs (5 reserved by AWS).',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'note' => 'CIDR block /24 gives 256 IPs (5 reserved by AWS).',
            ]);

        $this->assertDatabaseHas('lesson_notes', [
            'user_id' => $this->studentA->id,
            'course_id' => $this->course->id,
            'lesson_id' => $this->lesson->id,
            'note_text' => 'CIDR block /24 gives 256 IPs (5 reserved by AWS).',
        ]);

        // Retrieve note via GET
        $getRes = $this->actingAs($this->studentA, 'sanctum')
            ->getJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/note");

        $getRes->assertStatus(200)
            ->assertJson([
                'note' => 'CIDR block /24 gives 256 IPs (5 reserved by AWS).',
            ]);
    }

    public function test_student_notes_are_strictly_isolated_between_users(): void
    {
        LessonNote::create([
            'user_id' => $this->studentA->id,
            'course_id' => $this->course->id,
            'lesson_id' => $this->lesson->id,
            'note_text' => 'Confidential personal notes of Student A',
        ]);

        // Student B requests note for same lesson
        $response = $this->actingAs($this->studentB, 'sanctum')
            ->getJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/note");

        $response->assertStatus(200)
            ->assertJson([
                'note' => '',
            ]);
    }

    public function test_students_and_instructors_can_participate_in_qa_discussions(): void
    {
        // 1. Student A posts a question
        $qResponse = $this->actingAs($this->studentA, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/discussions", [
                'question' => 'How do NAT Gateways differ from NAT Instances in high availability mode?',
            ]);

        $qResponse->assertStatus(201)
            ->assertJsonFragment([
                'question_text' => 'How do NAT Gateways differ from NAT Instances in high availability mode?',
            ]);

        $discussionId = $qResponse->json('id');

        // 2. Tutor replies to question
        $rResponse = $this->actingAs($this->tutor, 'sanctum')
            ->postJson("/api/discussions/{$discussionId}/reply", [
                'reply' => 'NAT Gateways are AWS managed with auto-scaling up to 45Gbps bandwidth and built-in redundancy.',
            ]);

        $rResponse->assertStatus(201)
            ->assertJsonFragment([
                'reply_text' => 'NAT Gateways are AWS managed with auto-scaling up to 45Gbps bandwidth and built-in redundancy.',
            ]);

        // 3. Discussion list retrieval
        $listResponse = $this->actingAs($this->studentB, 'sanctum')
            ->getJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/discussions");

        $listResponse->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_unenrolled_student_cannot_post_to_cross_course_discussion(): void
    {
        $intruder = User::factory()->create(['role' => 'student', 'name' => 'Intruder']);

        $qResponse = $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/discussions", [
                'question' => 'Am I allowed to ask questions here?',
            ]);

        $qResponse->assertStatus(403);
    }

    public function test_unenrolled_student_cannot_save_or_read_lesson_notes(): void
    {
        $intruder = User::factory()->create(['role' => 'student', 'name' => 'Intruder']);

        // Writing a note requires course access -> 403
        $writeRes = $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/note", [
                'note' => 'Should not be able to save.',
            ]);
        $writeRes->assertStatus(403);

        // Reading a note also requires course access -> 403
        $readRes = $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/note");
        $readRes->assertStatus(403);

        $this->assertDatabaseMissing('lesson_notes', [
            'user_id' => $intruder->id,
            'course_id' => $this->course->id,
        ]);
    }

    public function test_note_rejects_lesson_not_belonging_to_course(): void
    {
        $otherCourse = Course::create([
            'title' => 'Different Course',
            'slug' => 'different-course',
            'description' => 'Unrelated course',
            'instructor' => 'Someone Else',
            'is_published' => true,
            'price' => 99,
            'duration' => '6 Weeks',
            'difficulty' => 'Beginner',
            'category' => 'GENERAL',
        ]);

        $otherSection = Section::create([
            'course_id' => $otherCourse->id,
            'title' => 'Other Module',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        $otherLesson = Lesson::create([
            'course_id' => $otherCourse->id,
            'section_id' => $otherSection->id,
            'title' => 'Other Lesson',
            'type' => 'video',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        // Attempting to save a note for a lesson that does not belong to the
        // given course must be rejected to keep course/lesson integrity.
        $res = $this->actingAs($this->studentA, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$otherLesson->id}/note", [
                'note' => 'Cross-course note attempt.',
            ]);
        $res->assertStatus(404);

        $this->assertDatabaseMissing('lesson_notes', [
            'user_id' => $this->studentA->id,
            'course_id' => $this->course->id,
            'lesson_id' => $otherLesson->id,
        ]);
    }

    public function test_discussion_rejects_lesson_not_belonging_to_course(): void
    {
        $otherCourse = Course::create([
            'title' => 'Discussion Other Course',
            'slug' => 'discussion-other-course',
            'description' => 'Unrelated course for discussion',
            'instructor' => 'Someone Else',
            'is_published' => true,
            'price' => 99,
            'duration' => '6 Weeks',
            'difficulty' => 'Beginner',
            'category' => 'GENERAL',
        ]);

        $otherSection = Section::create([
            'course_id' => $otherCourse->id,
            'title' => 'Other Discussion Module',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        $otherLesson = Lesson::create([
            'course_id' => $otherCourse->id,
            'section_id' => $otherSection->id,
            'title' => 'Other Discussion Lesson',
            'type' => 'video',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        // Listing discussions for a lesson that does not belong to the given
        // course must be rejected to preserve course/lesson integrity.
        $this->actingAs($this->studentA, 'sanctum')
            ->getJson("/api/courses/{$this->course->id}/lessons/{$otherLesson->id}/discussions")
            ->assertStatus(404);

        // Posting to a cross-course lesson must also be rejected.
        $this->actingAs($this->studentA, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$otherLesson->id}/discussions", [
                'question' => 'Cross-course discussion attempt.',
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('lesson_discussions', [
            'user_id' => $this->studentA->id,
            'course_id' => $this->course->id,
            'lesson_id' => $otherLesson->id,
        ]);
    }
}
