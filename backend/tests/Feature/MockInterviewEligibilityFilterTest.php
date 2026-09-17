<?php

namespace Tests\Feature;

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
 * P1-F blocker remediation: dashboard_status filtering happens BEFORE
 * database pagination, so page contents, total, and last_page always
 * describe the filtered population.
 *
 * Each test below fails against the old flow (paginate base population,
 * compute the page, filter the page in PHP): pages came back short, totals
 * described the unfiltered population, and matches were skipped.
 */
class MockInterviewEligibilityFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
            'description' => 'Filter fixture.',
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
        $student = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name) . '-' . Str::random(4) . '@example.com',
            'student_id' => 'FLT-' . strtoupper(Str::random(6)),
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

    private function markStatus(User $student, string $status, array $extra = []): void
    {
        StudentPlacementEligibility::create(array_merge([
            'user_id' => $student->id,
            'dashboard_status' => $status,
        ], $extra));
    }

    private function list(array $params = [])
    {
        $query = http_build_query($params);

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility' . ($query !== '' ? '?' . $query : ''));
    }

    private function serviceStatus(User $student): string
    {
        return MockInterviewService::checkStudentEligibility($student)['placement_dashboard_status'];
    }

    /**
     * 36 students interleaved across statuses in name order:
     * Pg 01 ENABLED, Pg 02 ELIGIBLE, Pg 03 DISABLED, Pg 04 ENABLED, ...
     *
     * @return array{enabled: int[], eligible: int[], disabled: int[]}
     */
    private function makeInterleavedRoster(): array
    {
        $ids = ['enabled' => [], 'eligible' => [], 'disabled' => []];

        for ($i = 1; $i <= 36; $i++) {
            $name = 'Pg ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            if ($i % 3 === 1) {
                $student = $this->makeStudent($name);
                $this->markStatus($student, StudentPlacementEligibility::STATUS_ENABLED);
                $ids['enabled'][] = $student->id;
            } elseif ($i % 3 === 2) {
                $ids['eligible'][] = $this->makeFullyEligibleStudent($name)->id;
            } else {
                $ids['disabled'][] = $this->makeStudent($name)->id;
            }
        }

        return $ids;
    }

    /* 1-3. cross-page filtered population (the old failure mode) */

    public function test_filtered_pages_cover_all_matches_without_skips(): void
    {
        $ids = $this->makeInterleavedRoster();

        $page1 = $this->list(['dashboard_status' => 'DISABLED', 'per_page' => 10])->assertOk();
        $page2 = $this->list(['dashboard_status' => 'DISABLED', 'per_page' => 10, 'page' => 2])->assertOk();

        // Total/last_page describe the 12 matching students, not all 36.
        $page1->assertJsonPath('total', 12)->assertJsonPath('last_page', 2);
        $page2->assertJsonPath('total', 12)->assertJsonPath('last_page', 2);

        // Page 1 holds the first 10 matches in roster order, page 2 the rest.
        $this->assertSame(array_slice($ids['disabled'], 0, 10), $page1->json('data.*.id'));
        $this->assertSame(array_slice($ids['disabled'], 10), $page2->json('data.*.id'));

        // Union of both pages is exactly the matching population: no skips.
        $this->assertSame($ids['disabled'], array_merge($page1->json('data.*.id'), $page2->json('data.*.id')));
    }

    /* 4-6. eligible filter across boundaries */

    public function test_eligible_filter_pages_are_complete(): void
    {
        $ids = $this->makeInterleavedRoster();

        $page1 = $this->list(['dashboard_status' => 'ELIGIBLE', 'per_page' => 10])->assertOk();
        $page2 = $this->list(['dashboard_status' => 'ELIGIBLE', 'per_page' => 10, 'page' => 2])->assertOk();

        $page1->assertJsonPath('total', 12)->assertJsonPath('last_page', 2);
        $this->assertSame(array_slice($ids['eligible'], 0, 10), $page1->json('data.*.id'));
        $this->assertSame(array_slice($ids['eligible'], 10), $page2->json('data.*.id'));
    }

    /* 7-8. total and last_page correctness */

    public function test_total_and_last_page_are_correct(): void
    {
        $this->makeInterleavedRoster();

        $this->list(['dashboard_status' => 'ENABLED', 'per_page' => 10])->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(10, 'data');

        $this->list(['dashboard_status' => 'ENABLED', 'per_page' => 5, 'page' => 3])->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('current_page', 3)
            ->assertJsonCount(2, 'data');
    }

    /* 9. full page despite interleaved non-matches */

    public function test_page_is_full_despite_interleaved_non_matches(): void
    {
        $this->makeInterleavedRoster();

        // Non-matching ENABLED/ELIGIBLE students sit between DISABLED matches
        // in roster order, yet page 1 still returns 10 matching rows.
        $this->list(['dashboard_status' => 'DISABLED', 'per_page' => 10])->assertOk()
            ->assertJsonCount(10, 'data');
    }

    /* Parity across every status path (killer correctness test) */

    public function test_each_status_filter_matches_service_computed_status(): void
    {
        $members = [
            'enabled' => $this->makeStudent('Qy Anna'),
            'suspended' => $this->makeStudent('Qy Bruno'),
            'disabled_record' => $this->makeStudent('Qy Carla'),
            'disabled_claimed' => $this->makeFullyEligibleStudent('Qy Dante'),
            'eligible_unclaimed' => $this->makeFullyEligibleStudent('Qy Elisa'),
            'eligible_plain' => $this->makeFullyEligibleStudent('Qy Fabio'),
            'disabled_plain' => $this->makeStudent('Qy Gina'),
            'eligible_override' => $this->makeStudent('Qy Hugo'),
        ];

        $this->markStatus($members['enabled'], StudentPlacementEligibility::STATUS_ENABLED);
        $this->markStatus($members['suspended'], StudentPlacementEligibility::STATUS_SUSPENDED);
        $this->markStatus($members['disabled_record'], StudentPlacementEligibility::STATUS_DISABLED);
        $this->markStatus($members['disabled_claimed'], StudentPlacementEligibility::STATUS_DISABLED, [
            'dashboard_status_updated_by' => $this->admin->id,
        ]);
        $this->markStatus($members['eligible_unclaimed'], StudentPlacementEligibility::STATUS_DISABLED);
        StudentPlacementEligibility::create([
            'user_id' => $members['eligible_override']->id,
            'is_admin_override' => true,
            'override_reason' => 'Registrar-approved admission.',
            'override_by' => $this->admin->id,
        ]);

        // Ground truth comes from the service itself.
        $expected = ['ENABLED' => [], 'SUSPENDED' => [], 'ELIGIBLE' => [], 'DISABLED' => []];

        foreach ($members as $student) {
            $expected[$this->serviceStatus($student)][] = $student->id;
        }

        $this->assertSame([$members['enabled']->id], $expected['ENABLED']);
        $this->assertSame([$members['suspended']->id], $expected['SUSPENDED']);
        $this->assertSame(
            [$members['disabled_record']->id, $members['disabled_claimed']->id, $members['disabled_plain']->id],
            $expected['DISABLED']
        );
        $this->assertSame(
            [$members['eligible_unclaimed']->id, $members['eligible_plain']->id, $members['eligible_override']->id],
            $expected['ELIGIBLE']
        );

        // The endpoint must agree exactly, per filter.
        foreach ($expected as $status => $ids) {
            sort($ids);

            $actual = $this->list(['dashboard_status' => $status, 'per_page' => 100])->assertOk()->json('data.*.id');
            sort($actual);

            $this->assertSame($ids, $actual, "Filter {$status} disagrees with service-computed status.");
        }
    }

    /* 10. search + dashboard_status together */

    public function test_search_and_dashboard_status_together(): void
    {
        $this->makeInterleavedRoster();

        // 'Pg 0' matches Pg 01..Pg 09 (3 DISABLED among them).
        $response = $this->list(['search' => 'Pg 0', 'dashboard_status' => 'DISABLED'])->assertOk();

        $response->assertJsonPath('total', 3);

        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('Pg 0', $item['name']);
            $this->assertSame('DISABLED', $item['eligibility']['placement_dashboard_status']);
        }
    }

    /* 11. no filter preserves existing results */

    public function test_no_filter_preserves_existing_results(): void
    {
        $ids = $this->makeInterleavedRoster();
        $all = array_merge($ids['enabled'], $ids['eligible'], $ids['disabled']);
        sort($all);

        $page1 = $this->list(['per_page' => 30])->assertOk();
        $page2 = $this->list(['per_page' => 30, 'page' => 2])->assertOk();

        $page1->assertJsonPath('total', 36);
        $this->assertSame($all, array_merge($page1->json('data.*.id'), $page2->json('data.*.id')));
    }

    /* Unknown filter values still match nothing (legacy behavior) */

    public function test_unknown_filter_value_matches_nothing(): void
    {
        $this->makeInterleavedRoster();

        $this->list(['dashboard_status' => 'NOPE'])->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    /* 12. eligibility payload unchanged under filtering */

    public function test_payload_unchanged_under_filter(): void
    {
        $ids = $this->makeInterleavedRoster();
        $student = User::find($ids['eligible'][0]);

        $item = $this->list(['dashboard_status' => 'ELIGIBLE'])->assertOk()->json('data.0');

        $this->assertSame(
            json_decode(json_encode(MockInterviewService::checkStudentEligibility($student)), true),
            $item['eligibility']
        );
    }

    /* 13. query count remains bounded with the predicate */

    public function test_query_count_bounded_with_filter(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $name = 'Qc ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $i % 2 === 0 ? $this->makeFullyEligibleStudent($name) : $this->makeStudent($name);
        }

        $first = $this->countListQueries(['dashboard_status' => 'ELIGIBLE', 'per_page' => 10]);

        for ($i = 21; $i <= 40; $i++) {
            $name = 'Qc ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $i % 2 === 0 ? $this->makeFullyEligibleStudent($name) : $this->makeStudent($name);
        }

        $second = $this->countListQueries(['dashboard_status' => 'ELIGIBLE', 'per_page' => 10]);

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

    /* 14. authorization remains unchanged */

    public function test_authorization_remains_unchanged(): void
    {
        $this->makeStudent('Qz Alice');

        // Unauthenticated first: actingAs persists for later requests in
        // the same test.
        $this->getJson('/api/admin/mock-interviews/eligibility?dashboard_status=ELIGIBLE')
            ->assertStatus(401);

        $this->list(['dashboard_status' => 'ELIGIBLE'])->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson('/api/admin/mock-interviews/eligibility?dashboard_status=ELIGIBLE')
            ->assertStatus(403);
    }
}
