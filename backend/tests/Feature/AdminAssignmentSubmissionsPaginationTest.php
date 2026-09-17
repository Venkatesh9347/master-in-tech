<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-E: admin assignment-submissions index is served through bounded
 * server-side pagination (P1-B perPage convention) instead of an unbounded
 * full-collection response. Filters, sorting, eager loads, item fields, and
 * authorization are unchanged.
 */
class AdminAssignmentSubmissionsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->course = Course::create([
            'title' => 'Grading Desk Course',
            'slug' => 'grading-desk-' . Str::random(6),
            'description' => 'Pagination fixture.',
            'instructor' => 'Fixture',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        $section = Section::create([
            'course_id' => $this->course->id,
            'title' => 'S',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $this->course->id,
            'section_id' => $section->id,
            'title' => 'L',
            'type' => 'assignment',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $this->assignment = Assignment::create([
            'lesson_id' => $lesson->id,
            'course_id' => $this->course->id,
            'title' => 'Project',
            'instructions' => 'Build it',
            'max_marks' => 100,
            'is_published' => true,
        ]);
    }

    private function submit(int $count, array $extra = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            $student = User::factory()->create(['role' => 'student']);

            AssignmentSubmission::create(array_merge([
                'user_id' => $student->id,
                'assignment_id' => $this->assignment->id,
                'lesson_id' => $this->assignment->lesson_id,
                'course_id' => $this->course->id,
                'submission_text' => 'attempt ' . $i,
                'status' => 'submitted',
            ], $extra));
        }
    }

    private function index(array $params = [])
    {
        $query = http_build_query($params);

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/assignments/submissions' . ($query !== '' ? '?' . $query : ''));
    }

    /* 1. default page size */

    public function test_default_page_size(): void
    {
        $this->submit(20);

        $response = $this->index()->assertOk();

        $response->assertJsonPath('per_page', 15);
        $response->assertJsonPath('total', 20);
        $response->assertJsonCount(15, 'data');
    }

    /* 2. requested per_page */

    public function test_requested_per_page(): void
    {
        $this->submit(10);

        $this->index(['per_page' => 5])->assertOk()->assertJsonCount(5, 'data');
    }

    /* 3. per_page minimum handling */

    public function test_per_page_minimum_handling(): void
    {
        $this->submit(20);

        // Zero / negative sizes fall back to the default page size.
        $this->index(['per_page' => 0])->assertOk()->assertJsonPath('per_page', 15);
        $this->index(['per_page' => -3])->assertOk()->assertJsonPath('per_page', 15);
    }

    /* 4. per_page maximum capped at existing limit */

    public function test_per_page_maximum_capped(): void
    {
        $this->submit(5);

        $this->index(['per_page' => 500])->assertOk()->assertJsonPath('per_page', 100);
    }

    /* 5. page 1 returns correct records */

    public function test_page_one_returns_newest_records(): void
    {
        $this->submit(20);

        $expected = AssignmentSubmission::orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(15)
            ->pluck('id')
            ->all();

        $this->index()->assertOk()->assertJsonPath('data.*.id', $expected);
    }

    /* 6. page 2 returns different records */

    public function test_page_two_returns_different_records(): void
    {
        $this->submit(20);

        $page1 = $this->index(['per_page' => 15])->assertOk()->json('data.*.id');
        $page2 = $this->index(['per_page' => 15, 'page' => 2])->assertOk()->json('data.*.id');

        $this->assertCount(15, $page1);
        $this->assertCount(5, $page2);
        $this->assertSame([], array_intersect($page1, $page2));
    }

    /* 7. pagination metadata/links are correct */

    public function test_pagination_metadata_and_links(): void
    {
        $this->submit(20);

        $response = $this->index(['per_page' => 15])->assertOk();

        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('last_page', 2);
        $response->assertJsonPath('total', 20);
        $this->assertNotEmpty($response->json('links'));
        $this->assertNotEmpty($response->json('first_page_url'));
        $this->assertNotEmpty($response->json('next_page_url'));
    }

    /* 8. existing filters still work */

    public function test_existing_filters_still_work(): void
    {
        $this->submit(3, ['status' => 'submitted']);
        $this->submit(2, ['status' => 'graded']);

        $this->index(['status' => 'graded'])->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');

        $this->index(['course_id' => $this->course->id])->assertOk()
            ->assertJsonPath('total', 5);

        $this->index(['course_id' => 999999])->assertOk()
            ->assertJsonPath('total', 0);
    }

    /* 9. existing ordering still works (deterministic with id tie-break) */

    public function test_existing_ordering_still_works(): void
    {
        $this->submit(3);

        // Force identical updated_at values: id tie-break keeps pages stable.
        AssignmentSubmission::query()->update(['updated_at' => now()->subMinute()]);

        $first = $this->index(['per_page' => 2])->assertOk()->json('data.*.id');
        $second = $this->index(['per_page' => 2, 'page' => 2])->assertOk()->json('data.*.id');

        $this->assertSame(
            AssignmentSubmission::orderBy('id', 'desc')->pluck('id')->all(),
            array_merge($first, $second)
        );
    }

    /* 10. eager-loaded fields remain present */

    public function test_eager_loaded_fields_remain_present(): void
    {
        $this->submit(1);

        $item = $this->index()->assertOk()->json('data.0');

        $this->assertArrayHasKey('user', $item);
        $this->assertArrayHasKey('email', $item['user']);
        $this->assertArrayHasKey('assignment', $item);
        $this->assertArrayHasKey('max_marks', $item['assignment']);
        $this->assertArrayHasKey('course', $item);
        $this->assertArrayHasKey('title', $item['course']);
    }

    /* 11. authorization remains unchanged */

    public function test_authorization_remains_unchanged(): void
    {
        $this->submit(1);

        // Guests are unauthenticated (asserted before any actingAs call in
        // this test, since actingAs persists for later requests).
        $this->getJson('/api/admin/assignments/submissions')->assertStatus(401);

        // Admin-only: students are rejected.
        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson('/api/admin/assignments/submissions')
            ->assertStatus(403);

        // Super admins retain access.
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->getJson('/api/admin/assignments/submissions')
            ->assertOk();
    }

    /* 12. no unbounded full collection is returned */

    public function test_no_unbounded_full_collection_returned(): void
    {
        $this->submit(30);

        $response = $this->index()->assertOk();

        // Only one page of items is serialized; the remainder is metadata.
        $this->assertCount(15, $response->json('data'));
        $response->assertJsonPath('total', 30);
        $response->assertJsonPath('last_page', 2);
    }
}
