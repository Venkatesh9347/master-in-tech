<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\MockInterview;
use App\Models\MockInterviewEvaluation;
use App\Models\MockInterviewer;
use App\Models\MockInterviewSlot;
use App\Models\Section;
use App\Models\StudentPlacementEligibility;
use App\Models\User;
use App\Services\MockInterviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-F: admin eligibility roster is served through bounded server-side
 * pagination with page-scoped batched eligibility inputs.
 *
 * Per-student results come from the same service routine as single-student
 * checks; several tests assert endpoint/service parity directly.
 */
class MockInterviewEligibilityListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $studentSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeCourse(): Course
    {
        $title = 'Course ' . Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Eligibility fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);
    }

    private function makeStudent(string $name, bool $completedCourse = false): User
    {
        $this->studentSeq++;

        $student = User::factory()->create([
            'name' => $name,
            'email' => 'elig-' . $this->studentSeq . '-' . Str::random(4) . '@example.com',
            'student_id' => 'ELIG-' . $this->studentSeq,
            'role' => 'student',
        ]);

        $course = $this->makeCourse();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => $completedCourse ? 'completed' : 'active',
            'progress_percentage' => $completedCourse ? 100.0 : 25.0,
            'enrolled_at' => now(),
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Core Lesson',
            'type' => 'video',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        if ($completedCourse) {
            LessonProgress::create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'section_id' => $section->id,
                'lesson_id' => $lesson->id,
                'completed' => true,
                'completed_at' => now(),
            ]);
        }

        return $student;
    }

    private function makeFullyEligibleStudent(string $name): User
    {
        $student = $this->makeStudent($name, true);

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

        return $student;
    }

    private function list(array $params = [])
    {
        $query = http_build_query($params);

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility' . ($query !== '' ? '?' . $query : ''));
    }

    private function serviceResult(User $student): array
    {
        return json_decode(json_encode(MockInterviewService::checkStudentEligibility($student)), true);
    }

    /* 1. default page size */

    public function test_default_page_size(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeStudent('Student ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $this->list()->assertOk()
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('total', 20)
            ->assertJsonCount(15, 'data');
    }

    /* 2. requested per_page */

    public function test_requested_per_page(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeStudent('Student ' . $i);
        }

        $this->list(['per_page' => 5])->assertOk()->assertJsonCount(5, 'data');
    }

    /* 3. per_page minimum */

    public function test_per_page_minimum(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeStudent('Student ' . $i);
        }

        $this->list(['per_page' => 0])->assertOk()->assertJsonPath('per_page', 15);
        $this->list(['per_page' => -4])->assertOk()->assertJsonPath('per_page', 15);
    }

    /* 4. per_page maximum */

    public function test_per_page_maximum(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeStudent('Student ' . $i);
        }

        $this->list(['per_page' => 500])->assertOk()->assertJsonPath('per_page', 100);
    }

    /* 5. page 1 */

    public function test_page_one(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeStudent('Student ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $expected = User::where('role', 'student')->orderBy('name')->orderBy('id')->limit(15)->pluck('id')->all();

        $this->list()->assertOk()->assertJsonPath('data.*.id', $expected);
    }

    /* 6. page 2 */

    public function test_page_two(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeStudent('Student ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $this->list(['per_page' => 15])->assertOk()->json('data.*.id');
        $page2 = $this->list(['per_page' => 15, 'page' => 2])->assertOk()->json('data.*.id');

        $this->assertCount(15, $page1);
        $this->assertCount(5, $page2);
        $this->assertSame([], array_intersect($page1, $page2));
    }

    /* 7. pagination metadata */

    public function test_pagination_metadata(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeStudent('Student ' . $i);
        }

        $this->list(['per_page' => 15])->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 20)
            ->assertJsonPath('per_page', 15);
    }

    /* 8. deterministic ordering */

    public function test_deterministic_ordering(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeStudent('Same Name');
        }

        $first = $this->list()->assertOk()->json('data.*.id');
        $second = $this->list()->assertOk()->json('data.*.id');

        $this->assertSame($first, $second);
        $this->assertSame(
            User::where('role', 'student')->orderBy('id')->pluck('id')->all(),
            $first
        );
    }

    /* 9. existing eligibility result shape */

    public function test_existing_result_shape(): void
    {
        $student = $this->makeStudent('Shape Student');

        $item = $this->list()->assertOk()->json('data.0');

        foreach (['id', 'name', 'email', 'phone', 'student_id', 'avatar', 'eligibility'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }

        foreach ([
            'is_eligible', 'course_completed', 'certificates_count', 'reasons', 'courses',
            'mock_interview_state', 'is_eligible_for_activation', 'placement_dashboard_status',
            'placement_dashboard_enabled', 'placement_eligible',
        ] as $key) {
            $this->assertArrayHasKey($key, $item['eligibility']);
        }
    }

    /* 10-11. eligible/ineligible results unchanged (endpoint/service parity) */

    public function test_eligible_student_result_unchanged(): void
    {
        $student = $this->makeFullyEligibleStudent('Eligible Parth');

        $item = $this->list()->assertOk()->json('data.0');

        $this->assertSame($student->id, $item['id']);
        $this->assertSame($this->serviceResult($student), $item['eligibility']);
        $this->assertTrue($item['eligibility']['is_eligible_for_activation']);
    }

    public function test_ineligible_student_result_unchanged(): void
    {
        $student = $this->makeStudent('Ineligible Ira');

        $item = $this->list()->assertOk()->json('data.0');

        $this->assertSame($this->serviceResult($student), $item['eligibility']);
        $this->assertFalse($item['eligibility']['is_eligible']);
        $this->assertSame('DISABLED', $item['eligibility']['placement_dashboard_status']);
    }

    /* 12. existing filters */

    public function test_existing_filters(): void
    {
        $this->makeStudent('Findable Farah');
        $this->makeStudent('Other Omar');

        // Search narrows by name, email, and student id code.
        $this->list(['search' => 'Farah'])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Findable Farah');

        $this->list(['search' => 'ELIG-2'])->assertOk()->assertJsonPath('total', 1);

        // Computed-status filter narrows the loaded page.
        $this->list(['dashboard_status' => 'DISABLED'])->assertOk()
            ->assertJsonCount(2, 'data');

        $enabled = $this->makeFullyEligibleStudent('Enabled Esha');

        StudentPlacementEligibility::create([
            'user_id' => $enabled->id,
            'dashboard_status' => StudentPlacementEligibility::STATUS_ENABLED,
        ]);

        $filtered = $this->list(['dashboard_status' => 'ENABLED'])->assertOk()->json('data');
        $this->assertNotEmpty($filtered);

        foreach ($filtered as $item) {
            $this->assertSame('ENABLED', $item['eligibility']['placement_dashboard_status']);
        }
    }

    /* 13-14. authorization */

    public function test_authorization(): void
    {
        $this->makeStudent('Auth Alice');

        $this->list()->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'tutor']), 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility')
            ->assertStatus(403);

        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility')
            ->assertStatus(403);
    }

    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/admin/mock-interviews/eligibility')->assertStatus(401);
    }

    /* 15. no cross-scope student exposure */

    public function test_no_cross_scope_exposure(): void
    {
        $student = $this->makeStudent('Scoped Sam');
        $tutor = User::factory()->create(['role' => 'tutor']);
        $counsellor = User::factory()->create(['role' => 'counsellor']);

        $ids = $this->list(['per_page' => 100])->assertOk()->json('data.*.id');

        $this->assertContains($student->id, $ids);
        $this->assertNotContains($this->admin->id, $ids);
        $this->assertNotContains($tutor->id, $ids);
        $this->assertNotContains($counsellor->id, $ids);
    }

    /* 16. response PII fields remain unchanged */

    public function test_pii_fields_unchanged(): void
    {
        $this->makeStudent('Pii Pam');

        $item = $this->list()->assertOk()->json('data.0');
        $body = json_encode($item);

        $this->assertArrayHasKey('email', $item);
        $this->assertArrayHasKey('phone', $item);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }

    /* 17. bounded student retrieval */

    public function test_bounded_student_retrieval(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->makeStudent('Bulk ' . $i);
        }

        $response = $this->list()->assertOk();

        $this->assertCount(15, $response->json('data'));
        $response->assertJsonPath('total', 30);
        $response->assertJsonPath('last_page', 2);
    }

    /* 18. query count does not scale with total students */

    public function test_query_count_does_not_scale_with_total_students(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeStudent('Counted ' . $i, $i % 2 === 0);
        }

        $first = $this->countListQueries(['per_page' => 15]);

        for ($i = 20; $i < 40; $i++) {
            $this->makeStudent('Counted ' . $i, $i % 2 === 0);
        }

        $second = $this->countListQueries(['per_page' => 15]);

        // Doubling the students outside the page adds zero queries.
        $this->assertSame($first, $second);
        // And the absolute count stays bounded (pre-fix: hundreds).
        $this->assertLessThan(60, $second);
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

    /* 19. eligibility correct with multiple students */

    public function test_eligibility_correct_with_multiple_students(): void
    {
        $eligible = $this->makeFullyEligibleStudent('Multi Mona');
        $ineligible = $this->makeStudent('Multi Max');
        $partial = $this->makeStudent('Multi Pia', true);

        $items = collect($this->list(['per_page' => 100])->assertOk()->json('data'))
            ->keyBy('id');

        foreach ([$eligible, $ineligible, $partial] as $student) {
            $this->assertSame($this->serviceResult($student), $items[$student->id]['eligibility']);
        }

        $this->assertTrue($items[$eligible->id]['eligibility']['is_eligible_for_activation']);
        $this->assertFalse($items[$ineligible->id]['eligibility']['is_eligible']);
    }
}
