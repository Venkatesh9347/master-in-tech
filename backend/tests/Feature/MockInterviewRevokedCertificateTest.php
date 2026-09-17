<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\MockInterview;
use App\Models\MockInterviewEvaluation;
use App\Models\MockInterviewer;
use App\Models\MockInterviewSlot;
use App\Models\User;
use App\Services\MockInterviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-G: only ACTIVE certificates count toward mock-interview eligibility.
 *
 * Revoked credentials confer nothing: no completion contribution, no
 * "verified certificate" reason, no ELIGIBLE filter membership. Active
 * behavior is unchanged.
 */
class MockInterviewRevokedCertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeStudent(string $name): array
    {
        $this->seq++;

        $student = User::factory()->create([
            'name' => $name,
            'email' => 'p1g-' . $this->seq . '-' . Str::random(4) . '@example.com',
            'student_id' => 'P1G-' . $this->seq,
            'role' => 'student',
        ]);

        $title = 'Course ' . Str::random(6);

        $course = Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'P1-G fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);

        // Deliberately incomplete: no lessons completed, so eligibility can
        // only come from certificates.
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 25.0,
            'enrolled_at' => now(),
        ]);

        return [$student, $course];
    }

    private function issueCertificate(User $student, Course $course, string $status): Certificate
    {
        return Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-P1G' . strtoupper(Str::random(8)),
            'issued_at' => now(),
            'status' => $status,
        ]);
    }

    private function serviceResult(User $student): array
    {
        return json_decode(json_encode(MockInterviewService::checkStudentEligibility($student)), true);
    }

    private function list(array $params = [])
    {
        $query = http_build_query($params);

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility' . ($query !== '' ? '?' . $query : ''));
    }

    /* 1. active certificate counts and confers eligibility */

    public function test_active_certificate_counts(): void
    {
        [$student] = $this->makeStudent('Active Ada');

        $this->issueCertificate($student, $this->makeStudentCourse($student), Certificate::STATUS_ACTIVE);

        $result = $this->serviceResult($student);

        $this->assertSame(1, $result['certificates_count']);
        $this->assertTrue($result['course_completed']);
        $this->assertTrue($result['is_eligible']);
    }

    /* 2. revoked-only certificate contributes nothing */

    public function test_revoked_only_certificate_contributes_nothing(): void
    {
        [$student, $course] = $this->makeStudent('Revoked Ron');

        $this->issueCertificate($student, $course, Certificate::STATUS_REVOKED);

        $result = $this->serviceResult($student);

        $this->assertSame(0, $result['certificates_count']);
        $this->assertFalse($result['course_completed']);
        $this->assertFalse($result['is_eligible']);
    }

    /* 3. active + revoked: only the active one counts */

    public function test_active_plus_revoked_counts_only_active(): void
    {
        [$student, $course] = $this->makeStudent('Mixed Mia');

        $this->issueCertificate($student, $course, Certificate::STATUS_ACTIVE);
        // Unique (user, course) allows one certificate per course, so the
        // revoked credential lives on a second course.
        $this->issueCertificate($student, $this->secondCourseFor($student), Certificate::STATUS_REVOKED);

        $result = $this->serviceResult($student);

        $this->assertSame(1, $result['certificates_count']);
        $this->assertTrue($result['is_eligible']);
    }

    /* 4. verified-certificate reason follows active count only */

    public function test_verified_reason_follows_active_count_only(): void
    {
        [$revokedStudent, $revokedCourse] = $this->makeStudent('Reason Ron');
        $this->issueCertificate($revokedStudent, $revokedCourse, Certificate::STATUS_REVOKED);

        $revokedReasons = implode("\n", $this->serviceResult($revokedStudent)['reasons']);
        $this->assertStringNotContainsString('verified course completion certificate', $revokedReasons);

        [$activeStudent, $activeCourse] = $this->makeStudent('Reason Ada');
        $this->issueCertificate($activeStudent, $activeCourse, Certificate::STATUS_ACTIVE);

        $activeReasons = implode("\n", $this->serviceResult($activeStudent)['reasons']);
        $this->assertStringContainsString('1 verified course completion certificate', $activeReasons);
    }

    /* 5. revocation after issuance removes the contribution */

    public function test_revocation_after_issuance_removes_contribution(): void
    {
        [$student, $course] = $this->makeStudent('Late Revoked Lou');

        $certificate = $this->issueCertificate($student, $course, Certificate::STATUS_ACTIVE);
        $this->assertTrue($this->serviceResult($student)['is_eligible']);

        $certificate->update([
            'status' => Certificate::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $this->admin->id,
            'revocation_reason' => 'P1-G regression fixture revocation.',
        ]);

        $after = $this->serviceResult($student);
        $this->assertSame(0, $after['certificates_count']);
        $this->assertFalse($after['is_eligible']);
    }

    /* 6-8. batch path parity + filter membership */

    public function test_batch_path_and_filters_use_active_only_rule(): void
    {
        [$activeStudent, $activeCourse] = $this->makeStudent('Batch Ada');
        $this->issueCertificate($activeStudent, $activeCourse, Certificate::STATUS_ACTIVE);
        $this->completeMockInterview($activeStudent);

        [$revokedStudent, $revokedCourse] = $this->makeStudent('Batch Ron');
        $this->issueCertificate($revokedStudent, $revokedCourse, Certificate::STATUS_REVOKED);

        $items = collect($this->list(['per_page' => 100])->assertOk()->json('data'))->keyBy('id');

        // Batch results match single-student results exactly.
        $this->assertSame($this->serviceResult($activeStudent), $items[$activeStudent->id]['eligibility']);
        $this->assertSame($this->serviceResult($revokedStudent), $items[$revokedStudent->id]['eligibility']);
        $this->assertSame(1, $items[$activeStudent->id]['eligibility']['certificates_count']);
        $this->assertSame(0, $items[$revokedStudent->id]['eligibility']['certificates_count']);

        // Predicate path agrees: revoked-only is DISABLED, never ELIGIBLE.
        $eligibleIds = $this->list(['dashboard_status' => 'ELIGIBLE', 'per_page' => 100])->assertOk()->json('data.*.id');
        $this->assertContains($activeStudent->id, $eligibleIds);
        $this->assertNotContains($revokedStudent->id, $eligibleIds);

        $disabledIds = $this->list(['dashboard_status' => 'DISABLED', 'per_page' => 100])->assertOk()->json('data.*.id');
        $this->assertContains($revokedStudent->id, $disabledIds);
        $this->assertNotContains($activeStudent->id, $disabledIds);
    }

    /* 9. no per-student certificate queries in the batch path */

    public function test_query_count_bounded(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            [$student, $course] = $this->makeStudent('Bulk ' . $i);
            $this->issueCertificate($student, $course, $i % 2 === 0 ? Certificate::STATUS_ACTIVE : Certificate::STATUS_REVOKED);
        }

        $first = $this->countListQueries(['per_page' => 15]);

        for ($i = 21; $i <= 40; $i++) {
            [$student, $course] = $this->makeStudent('Bulk ' . $i);
            $this->issueCertificate($student, $course, Certificate::STATUS_ACTIVE);
        }

        $second = $this->countListQueries(['per_page' => 15]);

        $this->assertSame($first, $second);
        $this->assertLessThan(80, $second);
    }

    private function countListQueries(array $params): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->list($params)->assertOk();

        return $count;
    }

    /* 12. authorization unchanged */

    public function test_authorization_unchanged(): void
    {
        $this->getJson('/api/admin/mock-interviews/eligibility')->assertStatus(401);

        [$student] = $this->makeStudent('Auth Alice');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility')
            ->assertStatus(403);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility')
            ->assertOk();
    }

    /* helpers */

    private function completeMockInterview(User $student): void
    {
        $interviewer = MockInterviewer::create([
            'name' => 'Fixture Interviewer ' . Str::random(4),
            'email' => 'fixture-' . Str::random(6) . '@example.com',
            'designation' => 'Engineer',
            'company' => 'Fixture Co',
            'years_of_experience' => 5,
            'is_active' => true,
        ]);

        $slot = MockInterviewSlot::create([
            'interviewer_id' => $interviewer->id,
            'slot_date' => now()->subDay()->toDateString(),
            'start_time' => '14:00',
            'end_time' => '14:45',
            'duration_minutes' => 45,
            'platform' => 'Google Meet',
            'status' => MockInterviewSlot::STATUS_COMPLETED,
        ]);

        $interview = MockInterview::create([
            'booking_code' => 'MIT-MOCK-' . strtoupper(Str::random(8)),
            'student_id' => $student->id,
            'slot_id' => $slot->id,
            'interviewer_id' => $interviewer->id,
            'scheduled_at' => now()->subDay(),
            'status' => MockInterview::STATUS_COMPLETED,
        ]);

        MockInterviewEvaluation::create([
            'mock_interview_id' => $interview->id,
            'student_id' => $student->id,
            'technical_knowledge' => 9,
            'programming_problem_solving' => 8,
            'communication' => 9,
            'confidence' => 8,
            'project_knowledge' => 9,
            'interview_readiness' => 9,
            'overall_rating' => 8.7,
            'strengths' => 'Strong fundamentals.',
            'areas_for_improvement' => 'Keep practicing.',
            'recommendation' => MockInterviewEvaluation::REC_READY_FOR_PLACEMENT,
            'is_published_to_student' => true,
            'evaluated_at' => now()->subHours(2),
        ]);
    }

    private function makeStudentCourse(User $student): Course
    {
        return Course::whereIn('id', CourseEnrollment::where('user_id', $student->id)->pluck('course_id'))->firstOrFail();
    }

    private function secondCourseFor(User $student): Course
    {
        $title = 'Second Course ' . Str::random(6);

        $course = Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'P1-G second course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 10.0,
            'enrolled_at' => now(),
        ]);

        return $course;
    }
}
