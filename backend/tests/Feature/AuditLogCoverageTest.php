<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C1: audit-log coverage for previously unlogged admin/CRM/tutor mutations.
 *
 * Each test proves the mutation emits the expected AuditLog event with the
 * correct actor + entity, and that failed/unauthorized attempts emit none.
 */
class AuditLogCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tutor;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->tutor = User::factory()->create(['role' => 'tutor']);
        $this->student = User::factory()->create(['role' => 'student']);

        $this->course = Course::create([
            'title' => 'Audit Course',
            'slug' => 'audit-course-' . Str::random(5),
            'description' => 'Audit coverage course.',
            'category' => 'Engineering',
            'instructor' => $this->tutor->name,
            'instructor_id' => $this->tutor->id,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'price' => 100,
            'is_published' => true,
        ]);
    }

    private function latestAudit(string $action): ?AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->first();
    }

    private function assertAuditCountUnchanged(string $action, callable $callback): void
    {
        $before = AuditLog::where('action', $action)->count();
        $callback();
        $this->assertSame($before, AuditLog::where('action', $action)->count());
    }

    public function test_admin_user_lifecycle_is_audited(): void
    {
        // Create (with password supplied: must never reach audit metadata).
        $create = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/users', [
            'name' => 'New Tutor',
            'email' => 'new.tutor@example.com',
            'password' => 'SuperSecret123!',
            'role' => 'tutor',
            'status' => 'active',
        ]);
        $create->assertStatus(201);
        $userId = $create->json('user.id');

        $created = $this->latestAudit('created_user');
        $this->assertNotNull($created);
        $this->assertSame($this->admin->id, $created->user_id);
        $this->assertSame($userId, $created->auditable_id);
        $this->assertSame('tutor', $created->new_values['role']);

        // Update.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/users/{$userId}", [
            'status' => 'disabled',
        ])->assertStatus(200);

        $updated = $this->latestAudit('updated_user');
        $this->assertNotNull($updated);
        $this->assertSame('active', $updated->old_values['status']);
        $this->assertSame('disabled', $updated->new_values['status']);

        // Role change records old and new roles.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/users/{$userId}/role", [
            'role' => 'counsellor',
        ])->assertStatus(200);

        $roleChanged = $this->latestAudit('updated_user_role');
        $this->assertNotNull($roleChanged);
        $this->assertSame('tutor', $roleChanged->old_values['role']);
        $this->assertSame('counsellor', $roleChanged->new_values['role']);

        // Delete.
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/users/{$userId}")
            ->assertStatus(200);

        $deleted = $this->latestAudit('deleted_user');
        $this->assertNotNull($deleted);
        $this->assertSame('new.tutor@example.com', $deleted->old_values['email']);
        $this->assertNull($deleted->new_values);

        // Unauthorized attempt emits no user audit events.
        $this->assertAuditCountUnchanged('created_user', function () {
            $this->actingAs($this->student, 'sanctum')->postJson('/api/admin/users', [
                'name' => 'Hacker',
                'email' => 'hacker@example.com',
                'role' => 'student',
            ])->assertStatus(403);
        });
    }

    public function test_admin_event_crud_is_audited(): void
    {
        $payload = [
            'title' => 'Audit Summit',
            'description' => 'Annual audit summit.',
            'speaker_name' => 'Jane Doe',
            'speaker_designation' => 'CTO',
            'event_date' => now()->addWeek()->format('Y-m-d H:i'),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration' => 120,
            'mode' => 'online',
            'status' => 'draft',
        ];

        $create = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/events', $payload);
        $create->assertStatus(201);
        $eventId = $create->json('id');

        $this->assertNotNull($this->latestAudit('created_event'));

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/events/{$eventId}", [
            'status' => 'published',
        ])->assertStatus(200);

        $updated = $this->latestAudit('updated_event');
        $this->assertSame('draft', $updated->old_values['status']);
        $this->assertSame('published', $updated->new_values['status']);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/events/{$eventId}")
            ->assertStatus(204);

        $deleted = $this->latestAudit('deleted_event');
        $this->assertNotNull($deleted);
        $this->assertSame('Audit Summit', $deleted->old_values['title']);
    }

    public function test_assignment_grading_is_audited(): void
    {
        $section = Section::create([
            'course_id' => $this->course->id, 'title' => 'Module', 'sort_order' => 0, 'is_published' => true,
        ]);
        $lesson = Lesson::create([
            'course_id' => $this->course->id, 'section_id' => $section->id,
            'title' => 'Assignment Lesson', 'type' => 'assignment', 'is_published' => true,
        ]);
        CourseEnrollment::create([
            'user_id' => $this->student->id, 'course_id' => $this->course->id, 'status' => 'active',
        ]);
        $assignment = Assignment::create([
            'lesson_id' => $lesson->id, 'course_id' => $this->course->id,
            'title' => 'Build It', 'instructions' => 'Do it.', 'max_marks' => 100, 'is_published' => true,
        ]);

        $submit = $this->actingAs($this->student, 'sanctum')->postJson(
            "/api/assignments/{$assignment->id}/submit",
            ['submission_text' => 'My work.', 'file_url' => 'https://example.com/work']
        );
        $submit->assertCreated();
        $submissionId = $submit->json('submission.id');

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/assignments/submissions/{$submissionId}/grade",
            ['score' => 80, 'feedback' => 'Good.', 'status' => 'graded']
        )->assertOk();

        $graded = $this->latestAudit('graded_assignment_submission');
        $this->assertNotNull($graded);
        $this->assertSame($this->admin->id, $graded->user_id);
        $this->assertSame($submissionId, $graded->auditable_id);
        $this->assertSame(80.0, (float) $graded->new_values['score']);
        $this->assertSame($this->student->id, $graded->new_values['user_id']);
        $this->assertArrayNotHasKey('feedback', $graded->new_values);

        // Failed grading emits no false success event.
        $this->assertAuditCountUnchanged('graded_assignment_submission', function () {
            $this->actingAs($this->admin, 'sanctum')->postJson(
                '/api/admin/assignments/submissions/999999/grade',
                ['score' => 10]
            )->assertStatus(404);
        });
    }

    public function test_enquiry_mutations_are_audited(): void
    {
        $enquiry = Enquiry::create([
            'name' => 'Lead Person', 'email' => 'lead.person@example.com',
            'phone' => '+91 9000000001', 'course_id' => $this->course->id, 'status' => Enquiry::STATUS_NEW,
        ]);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/enquiries/{$enquiry->id}", [
            'status' => Enquiry::STATUS_CONTACTED,
        ])->assertStatus(200);

        $updated = $this->latestAudit('updated_enquiry');
        $this->assertNotNull($updated);
        $this->assertSame(Enquiry::STATUS_NEW, $updated->old_values['status']);
        $this->assertSame($this->admin->id, $updated->user_id);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/enquiries/{$enquiry->id}/notes", [
            'note' => 'Called, interested.',
        ])->assertStatus(201);

        $noted = $this->latestAudit('created_enquiry_note');
        $this->assertNotNull($noted);
        $this->assertSame($enquiry->id, $noted->new_values['enquiry_id']);
        $this->assertArrayNotHasKey('note', $noted->new_values);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $this->course->id,
        ])->assertStatus(200);

        $enrolled = $this->latestAudit('created_enrollment');
        $this->assertNotNull($enrolled);
        $this->assertSame($this->course->id, $enrolled->new_values['course_id']);
        $this->assertSame($enquiry->id, $enrolled->new_values['enquiry_id']);
    }

    public function test_crm_activity_and_follow_ups_are_audited(): void
    {
        $lead = Enquiry::create([
            'name' => 'CRM Lead', 'email' => 'crm.lead@example.com',
            'phone' => '+91 9000000002', 'course_id' => $this->course->id, 'status' => Enquiry::STATUS_NEW,
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
            'activity_type' => 'note',
            'title' => 'Intro call',
        ])->assertStatus(201);

        $activity = $this->latestAudit('created_crm_activity');
        $this->assertNotNull($activity);
        $this->assertSame($this->admin->id, $activity->user_id);
        $this->assertSame('note', $activity->new_values['activity_type']);

        $stored = $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/crm/leads/{$lead->id}/follow-ups",
            ['scheduled_at' => now()->addDay()->toDateTimeString(), 'title' => 'Follow up']
        );
        $stored->assertStatus(201);
        $followUpId = $stored->json('follow_up.id');

        $created = $this->latestAudit('created_crm_follow_up');
        $this->assertNotNull($created);
        $this->assertSame($followUpId, $created->new_values['follow_up_id']);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/crm/follow-ups/{$followUpId}", [
            'status' => 'completed',
        ])->assertStatus(200);

        $followUpUpdated = $this->latestAudit('updated_crm_follow_up');
        $this->assertNotNull($followUpUpdated);
        $this->assertSame('pending', $followUpUpdated->old_values['status']);
        $this->assertSame('completed', $followUpUpdated->new_values['status']);
    }

    public function test_class_session_materials_are_audited_for_admin_and_tutor(): void
    {
        Storage::fake('local');

        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Materials Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123',
            'scheduled_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $file = fn () => UploadedFile::fake()->create('notes.pdf', 500, 'application/pdf');

        // Admin upload + delete.
        $adminUpload = $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/class-sessions/{$session->id}/materials",
            ['title' => 'Admin Notes', 'file' => $file()]
        );
        $adminUpload->assertStatus(201);
        $materialId = $adminUpload->json('material.id');

        $created = $this->latestAudit('created_class_session_material');
        $this->assertNotNull($created);
        $this->assertSame($this->admin->id, $created->user_id);
        $this->assertSame($session->id, $created->new_values['class_session_id']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/class-sessions/{$session->id}/materials/{$materialId}")
            ->assertStatus(200);

        $deleted = $this->latestAudit('deleted_class_session_material');
        $this->assertNotNull($deleted);
        $this->assertSame('Admin Notes', $deleted->old_values['title']);

        // Tutor upload on own session (authorization preserved).
        $tutorUpload = $this->actingAs($this->tutor, 'sanctum')->postJson(
            "/api/tutor/class-sessions/{$session->id}/materials",
            ['title' => 'Tutor Notes', 'file' => $file()]
        );
        $tutorUpload->assertStatus(201);

        $tutorCreated = AuditLog::where('action', 'created_class_session_material')
            ->latest('id')->first();
        $this->assertSame($this->tutor->id, $tutorCreated->user_id);
    }

    public function test_tutor_course_quiz_assignment_and_grading_are_audited(): void
    {
        // Tutor-owned course mutations via the tutor API (admin-gated paths).
        $store = $this->actingAs($this->admin, 'sanctum')->postJson('/api/tutor/courses', [
            'title' => 'Tutor Course',
            'description' => 'Desc.',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
        ]);
        $store->assertStatus(201);
        $courseId = $store->json('id') ?? $store->json('course.id');

        $this->assertNotNull($this->latestAudit('created_course'));

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/tutor/courses/{$courseId}", [
            'title' => 'Tutor Course Renamed',
        ])->assertStatus(200);

        $updated = $this->latestAudit('updated_course');
        $this->assertSame('Tutor Course', $updated->old_values['title']);
        $this->assertSame('Tutor Course Renamed', $updated->new_values['title']);

        // Quiz + assignment authoring on an owned lesson.
        $section = Section::create([
            'course_id' => $courseId, 'title' => 'Module', 'sort_order' => 0, 'is_published' => true,
        ]);
        $lesson = Lesson::create([
            'course_id' => $courseId, 'section_id' => $section->id,
            'title' => 'Authoring Lesson', 'type' => 'video', 'is_published' => true,
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/tutor/courses/{$courseId}/lessons/{$lesson->id}/quiz",
            [
                'title' => 'Quiz One',
                'passing_score' => 70,
                'questions' => [
                    [
                        'question' => 'What is 2 + 2?',
                        'marks' => 10,
                        'options' => [
                            ['option_text' => '4', 'is_correct' => true],
                            ['option_text' => '5', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        )->assertStatus(200);

        $savedQuiz = $this->latestAudit('saved_quiz');
        $this->assertNotNull($savedQuiz);
        $this->assertSame('Quiz One', $savedQuiz->new_values['title']);
        $this->assertSame(1, $savedQuiz->new_values['question_count']);

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/tutor/courses/{$courseId}/lessons/{$lesson->id}/assignment",
            ['title' => 'HW One', 'instructions' => 'Do it.', 'max_marks' => 50]
        )->assertStatus(200);

        $savedAssignment = $this->latestAudit('saved_assignment');
        $this->assertNotNull($savedAssignment);
        $this->assertSame(50.0, (float) $savedAssignment->new_values['max_marks']);

        // Tutor grading on own course.
        $ownedCourse = Course::where('id', $courseId)->first();
        $ownedCourse->forceFill(['instructor_id' => $this->tutor->id])->save();

        $assignment = Assignment::where('lesson_id', $lesson->id)->first();
        CourseEnrollment::create([
            'user_id' => $this->student->id, 'course_id' => $courseId, 'status' => 'active',
        ]);
        $submission = $this->actingAs($this->student, 'sanctum')->postJson(
            "/api/assignments/{$assignment->id}/submit",
            ['submission_text' => 'Done.']
        );
        $submission->assertCreated();

        $this->actingAs($this->tutor, 'sanctum')->postJson(
            "/api/tutor/submissions/{$submission->json('submission.id')}/grade",
            ['score' => 40, 'status' => 'graded']
        )->assertOk();

        $graded = $this->latestAudit('graded_assignment_submission');
        $this->assertSame($this->tutor->id, $graded->user_id);
        $this->assertSame(40.0, (float) $graded->new_values['score']);

        // Course deletion audited.
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/tutor/courses/{$courseId}")
            ->assertStatus(200);
        $this->assertNotNull($this->latestAudit('deleted_course'));
    }

    public function test_curriculum_crud_is_audited(): void
    {
        // Section lifecycle.
        $store = $this->actingAs($this->tutor, 'sanctum')->postJson(
            "/api/courses/{$this->course->id}/sections",
            ['title' => 'New Module']
        );
        $store->assertStatus(201);
        $sectionId = $store->json('id');
        $this->assertNotNull($this->latestAudit('created_section'));

        $this->actingAs($this->tutor, 'sanctum')->putJson(
            "/api/courses/{$this->course->id}/sections/{$sectionId}",
            ['title' => 'Renamed Module']
        )->assertStatus(200);

        $sectionUpdated = $this->latestAudit('updated_section');
        $this->assertSame('Renamed Module', $sectionUpdated->new_values['title']);

        $this->actingAs($this->tutor, 'sanctum')->postJson(
            "/api/courses/{$this->course->id}/sections/{$sectionId}/toggle-publish"
        )->assertStatus(200);

        $unpublished = $this->latestAudit('unpublished_section');
        $this->assertNotNull($unpublished);
        $this->assertTrue($unpublished->old_values['is_published']);

        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/courses/{$this->course->id}/reorder", [
            'sections' => [['id' => $sectionId, 'sort_order' => 0]],
        ])->assertStatus(200);

        $reordered = $this->latestAudit('reordered_curriculum');
        $this->assertSame($this->course->id, $reordered->new_values['course_id']);

        // Lesson lifecycle.
        $lessonStore = $this->actingAs($this->tutor, 'sanctum')->postJson(
            "/api/courses/{$this->course->id}/sections/{$sectionId}/lessons",
            ['title' => 'New Lesson', 'type' => 'video']
        );
        $lessonStore->assertStatus(201);
        $lessonId = $lessonStore->json('id');
        $this->assertNotNull($this->latestAudit('created_lesson'));

        $this->actingAs($this->tutor, 'sanctum')->putJson(
            "/api/courses/{$this->course->id}/sections/{$sectionId}/lessons/{$lessonId}",
            ['title' => 'Renamed Lesson']
        )->assertStatus(200);
        $this->assertNotNull($this->latestAudit('updated_lesson'));

        $this->actingAs($this->tutor, 'sanctum')->postJson(
            "/api/courses/{$this->course->id}/sections/{$sectionId}/lessons/{$lessonId}/toggle-publish"
        )->assertStatus(200);
        $this->assertNotNull($this->latestAudit('unpublished_lesson'));

        $this->actingAs($this->tutor, 'sanctum')->deleteJson(
            "/api/courses/{$this->course->id}/sections/{$sectionId}/lessons/{$lessonId}"
        )->assertStatus(200);
        $this->assertNotNull($this->latestAudit('deleted_lesson'));

        $this->actingAs($this->tutor, 'sanctum')->deleteJson(
            "/api/courses/{$this->course->id}/sections/{$sectionId}"
        )->assertStatus(200);
        $this->assertNotNull($this->latestAudit('deleted_section'));

        // Cross-tutor curriculum access stays forbidden and unaudited.
        $otherTutor = User::factory()->create(['role' => 'tutor']);
        $this->assertAuditCountUnchanged('created_section', function () use ($otherTutor) {
            $this->actingAs($otherTutor, 'sanctum')->postJson(
                "/api/courses/{$this->course->id}/sections",
                ['title' => 'Hijack Module']
            )->assertStatus(403);
        });
    }

    public function test_live_class_lifecycle_is_audited(): void
    {
        $store = $this->actingAs($this->tutor, 'sanctum')->postJson(
            "/api/tutor/courses/{$this->course->id}/live-classes",
            [
                'title' => 'Live Kickoff',
                'class_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
            ]
        );
        $store->assertStatus(201);
        $liveClassId = $store->json('live_class.id');

        $this->assertNotNull($this->latestAudit('created_live_class'));

        $this->actingAs($this->tutor, 'sanctum')->putJson("/api/tutor/live-classes/{$liveClassId}", [
            'title' => 'Live Kickoff Renamed',
        ])->assertStatus(200);

        $updated = $this->latestAudit('updated_live_class');
        $this->assertSame('Live Kickoff', $updated->old_values['title']);

        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/tutor/live-classes/{$liveClassId}/start")
            ->assertStatus(200);
        $this->assertSame('live', $this->latestAudit('started_live_class')->new_values['status']);

        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/tutor/live-classes/{$liveClassId}/end")
            ->assertStatus(200);
        $this->assertSame('completed', $this->latestAudit('ended_live_class')->new_values['status']);

        $this->actingAs($this->tutor, 'sanctum')->deleteJson("/api/tutor/live-classes/{$liveClassId}")
            ->assertStatus(200);
        $this->assertNotNull($this->latestAudit('deleted_live_class'));
    }

    public function test_certificate_generation_is_audited(): void
    {
        $section = Section::create([
            'course_id' => $this->course->id, 'title' => 'Module', 'sort_order' => 0, 'is_published' => true,
        ]);
        $lesson = Lesson::create([
            'course_id' => $this->course->id, 'section_id' => $section->id,
            'title' => 'Final Lesson', 'type' => 'video', 'is_published' => true,
        ]);
        CourseEnrollment::create([
            'user_id' => $this->student->id, 'course_id' => $this->course->id,
            'status' => 'active', 'progress_percentage' => 100,
        ]);
        LessonProgress::create([
            'user_id' => $this->student->id, 'course_id' => $this->course->id,
            'lesson_id' => $lesson->id, 'section_id' => $section->id,
            'completed' => true, 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/certificate");
        $response->assertCreated();

        $generated = $this->latestAudit('generated_certificate');
        $this->assertNotNull($generated);
        $this->assertSame($this->student->id, $generated->user_id);
        $this->assertSame($this->course->id, $generated->new_values['course_id']);
        $this->assertSame($response->json('certificate.certificate_code'), $generated->new_values['certificate_code']);
    }

    public function test_audit_metadata_contains_no_secrets(): void
    {
        // Exercise a password-bearing mutation, then scan every audit row
        // created in this test for secret-bearing keys or values.
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/users', [
            'name' => 'Secret Probe',
            'email' => 'secret.probe@example.com',
            'password' => 'TopSecret123!',
            'role' => 'student',
            'status' => 'active',
        ])->assertStatus(201);

        $rows = AuditLog::all();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $blob = strtolower(json_encode([$row->old_values, $row->new_values]));
            $this->assertStringNotContainsString('topsecret123', $blob);
            $this->assertStringNotContainsString('password', $blob);
            $this->assertDoesNotMatchRegularExpression('/\b otp \b/x', $blob);
            $this->assertStringNotContainsString('api key', $blob);
            $this->assertStringNotContainsString('webhook secret', $blob);
        }
    }
}
