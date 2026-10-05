<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B16: bounded, deterministic server-side pagination for the PUBLIC catalog
 * (GET /api/public/courses).
 *
 * Deliberately does NOT cover GET /api/courses, which remains the complete
 * catalog/lookup endpoint (bare array, no pagination) for admin dropdowns,
 * student lookup and the other consumers.
 */
class PublicCatalogPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const PER_PAGE = 12;
    private const MAX_PER_PAGE = 24;

    private function makeCourses(int $count, array $overrides = [], string $prefix = 'paged'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Course::create(array_merge([
                'title' => sprintf('%s Course %03d', ucfirst($prefix), $i),
                'slug' => sprintf('%s-course-%03d', $prefix, $i),
                'description' => 'Pagination fixture course',
                'instructor' => 'Fixture Faculty',
                'duration' => '6 weeks',
                'difficulty' => 'Basic',
                'is_published' => true,
                'status' => 'published',
                'priority' => 50,
            ], $overrides));
        }
    }

    private function ids(string $query = ''): array
    {
        return $this->getJson('/api/public/courses'.$query)
            ->assertOk()
            ->json('data.*.id');
    }

    // ---- 1/7/8/9: default page size + metadata + total ----

    public function test_default_page_size_is_twelve_and_metadata_is_authoritative(): void
    {
        $this->makeCourses(30);

        $res = $this->getJson('/api/public/courses')->assertOk();

        $res->assertJsonCount(self::PER_PAGE, 'data');
        $res->assertJsonPath('current_page', 1);
        $res->assertJsonPath('per_page', self::PER_PAGE);
        $res->assertJsonPath('total', 30);
        $res->assertJsonPath('last_page', 3);
        $res->assertJsonPath('from', 1);
        $res->assertJsonPath('to', self::PER_PAGE);
    }

    // ---- 2/3: explicit page 1 and page 2 ----

    public function test_page_one_and_two_return_distinct_rows(): void
    {
        $this->makeCourses(30);

        $first = $this->getJson('/api/public/courses?page=1&per_page=12')->assertOk();
        $second = $this->getJson('/api/public/courses?page=2&per_page=12')->assertOk();

        $first->assertJsonCount(12, 'data');
        $second->assertJsonCount(12, 'data');
        $second->assertJsonPath('current_page', 2);
        $second->assertJsonPath('from', 13);

        $p1 = $first->json('data.*.id');
        $p2 = $second->json('data.*.id');
        $this->assertEmpty(array_intersect($p1, $p2), 'Page 1 and page 2 must not share course ids.');
    }

    // ---- 10: no duplicates across every page ----

    public function test_no_duplicate_course_ids_across_all_pages(): void
    {
        $this->makeCourses(30);

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $seen = array_merge($seen, $this->ids("?page={$page}&per_page=12"));
        }

        $this->assertCount(30, $seen);
        $this->assertCount(30, array_unique($seen), 'A course must not appear on two pages.');
    }

    // ---- 4: explicit per_page ----

    public function test_explicit_per_page_is_honoured(): void
    {
        $this->makeCourses(30);

        $res = $this->getJson('/api/public/courses?per_page=12')->assertOk();
        $res->assertJsonPath('per_page', 12);
        $res->assertJsonCount(12, 'data');
    }

    // ---- 5: maximum per_page ----

    public function test_maximum_per_page_is_allowed(): void
    {
        $this->makeCourses(30);

        $res = $this->getJson('/api/public/courses?per_page='.self::MAX_PER_PAGE)->assertOk();
        $res->assertJsonPath('per_page', self::MAX_PER_PAGE);
        $res->assertJsonCount(self::MAX_PER_PAGE, 'data');
    }

    // ---- 6: oversized per_page is capped (security) ----

    public function test_oversized_per_page_is_capped_not_accepted(): void
    {
        $this->makeCourses(30);

        $res = $this->getJson('/api/public/courses?per_page=100000')->assertOk();
        $res->assertJsonPath('per_page', self::MAX_PER_PAGE);
        $this->assertLessThanOrEqual(
            self::MAX_PER_PAGE,
            count($res->json('data')),
            'An oversized per_page must never return the whole catalog in one response.',
        );
    }

    public function test_legacy_limit_parameter_is_also_bounded(): void
    {
        $this->makeCourses(30);

        $res = $this->getJson('/api/public/courses?limit=100000')->assertOk();
        $res->assertJsonPath('per_page', self::MAX_PER_PAGE);
        $this->assertLessThanOrEqual(self::MAX_PER_PAGE, count($res->json('data')));
    }

    // ---- B16-N: hostile / invalid input is handled safely ----

    public function test_invalid_pagination_input_is_handled_safely(): void
    {
        $this->makeCourses(30);

        foreach (['?page=0', '?page=-1', '?page=abc', '?per_page=abc', '?per_page=0', '?per_page=-5'] as $query) {
            $res = $this->getJson('/api/public/courses'.$query)->assertOk();
            $this->assertIsArray($res->json('data'), "Expected an array for {$query}");
            $this->assertLessThanOrEqual(self::MAX_PER_PAGE, count($res->json('data')));
        }
    }

    public function test_out_of_range_page_returns_empty_data_without_error(): void
    {
        $this->makeCourses(15);

        $res = $this->getJson('/api/public/courses?page=999&per_page=12')->assertOk();
        $res->assertJsonCount(0, 'data');
        $res->assertJsonPath('total', 15);
    }

    // ---- B16-C: filters apply BEFORE pagination ----

    public function test_search_filters_before_paginating(): void
    {
        $this->makeCourses(20);
        Course::create([
            'title' => 'Quantum Computing Specialization',
            'slug' => 'quantum-computing-specialization',
            'description' => 'Distinctive search term',
            'instructor' => 'Fixture Faculty',
            'duration' => '6 weeks',
            'difficulty' => 'Basic',
            'is_published' => true,
            'status' => 'published',
            'priority' => 1,
        ]);

        $res = $this->getJson('/api/public/courses?search=Quantum&per_page=12')->assertOk();

        $res->assertJsonPath('total', 1);
        $res->assertJsonCount(1, 'data');
        $this->assertSame('Quantum Computing Specialization', $res->json('data.0.title'));
    }

    public function test_category_filter_applies_before_paginating(): void
    {
        $this->makeCourses(15, ['category' => 'Unfiltered Group'], 'unfiltered');
        $this->makeCourses(4, ['category' => 'Robotics'], 'robotics');

        $res = $this->getJson('/api/public/courses?category=Robotics&per_page=12')->assertOk();

        $res->assertJsonPath('total', 4);
        $res->assertJsonCount(4, 'data');
        foreach ($res->json('data') as $course) {
            $this->assertSame('Robotics', $course['category']);
        }
    }

    public function test_level_filter_applies_before_paginating(): void
    {
        $this->makeCourses(15, ['difficulty' => 'Basic'], 'basic');
        $this->makeCourses(6, ['difficulty' => 'Advanced'], 'advanced');

        $res = $this->getJson('/api/public/courses?difficulty=Advanced&per_page=12')->assertOk();

        $res->assertJsonPath('total', 6);
        $res->assertJsonCount(6, 'data');
        foreach ($res->json('data') as $course) {
            $this->assertSame('Advanced', $course['difficulty']);
        }
    }

    public function test_combined_filters_narrow_the_total(): void
    {
        $this->makeCourses(20, ['category' => 'Robotics', 'difficulty' => 'Advanced'], 'advanced-robotics');
        $this->makeCourses(3, ['category' => 'Robotics', 'difficulty' => 'Basic'], 'basic-robotics');

        $res = $this->getJson('/api/public/courses?category=Robotics&difficulty=Advanced&per_page=12')->assertOk();
        $res->assertJsonPath('total', 20);
        $res->assertJsonCount(12, 'data');
    }

    // ---- B16-D: deterministic ordering so rows cannot shift between pages ----

    public function test_ordering_is_deterministic_across_identical_priorities(): void
    {
        // Every fixture shares priority 50, which is exactly the tie that made
        // the previous priority-only ordering non-deterministic.
        $this->makeCourses(25);

        $firstRun = array_merge($this->ids('?page=1&per_page=12'), $this->ids('?page=2&per_page=12'));

        $order = $this->getJson('/api/public/courses?per_page=25')->assertOk()->json('data.*.id');
        $this->assertSame($order, $firstRun, 'Course order must be stable across requests.');

        $priorities = $this->getJson('/api/public/courses?per_page=25')->json('data.*.priority');
        $sorted = $priorities;
        sort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $priorities, 'Ordering must remain priority-first.');
    }

    // ---- visibility rules are preserved ----

    public function test_unpublished_and_archived_courses_stay_excluded(): void
    {
        $this->makeCourses(3);
        Course::create([
            'title' => 'Hidden Draft Course',
            'slug' => 'hidden-draft-course',
            'description' => 'Should never be public',
            'instructor' => 'Fixture Faculty',
            'duration' => '6 weeks',
            'difficulty' => 'Basic',
            'is_published' => false,
            'status' => 'draft',
            'priority' => 1,
        ]);
        Course::create([
            'title' => 'Archived Course',
            'slug' => 'archived-course',
            'description' => 'Should never be public',
            'instructor' => 'Fixture Faculty',
            'duration' => '6 weeks',
            'difficulty' => 'Basic',
            'is_published' => true,
            'status' => 'archived',
            'priority' => 1,
        ]);

        $res = $this->getJson('/api/public/courses')->assertOk();
        $titles = array_column($res->json('data'), 'title');

        $this->assertNotContains('Hidden Draft Course', $titles);
        $this->assertNotContains('Archived Course', $titles);
    }

    // ---- B16-F: /api/courses must keep its bare-array contract ----

    public function test_complete_catalog_endpoint_still_returns_a_bare_array(): void
    {
        $this->makeCourses(30);

        $res = $this->getJson('/api/courses')->assertOk();

        $this->assertIsArray($res->json(), '/api/courses must remain a bare array.');
        $this->assertNull($res->json('current_page'), '/api/courses must not gain paginator metadata.');
        $this->assertCount(30, $res->json());

        // Its pagination-looking query parameters stay inert by design.
        $this->assertCount(30, $this->getJson('/api/courses?per_page=2')->assertOk()->json());
        $this->assertCount(30, $this->getJson('/api/courses?page=2')->assertOk()->json());
        $this->assertCount(30, $this->getJson('/api/courses?per_page=100000')->assertOk()->json());
    }
}
