<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class CourseCatalogOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function createPublishedCourse(array $overrides = []): Course
    {
        $data = array_merge([
            'title' => Str::random(12),
            'slug' => Str::slug(Str::random(12)),
            'description' => 'Test course description.',
            'instructor' => 'Test Faculty',
            'duration' => '6 Weeks',
            'difficulty' => 'Intermediate',
            'category' => 'Full Stack',
            'is_published' => true,
            'status' => 'published',
            'priority' => 100,
        ], $overrides);

        return Course::create($data);
    }

    // 1. The public catalog preserves the configured C-panel priority order:
    //    a lower priority value (higher priority) always renders first.
    public function test_public_catalog_orders_courses_by_configured_priority(): void
    {
        $priorityOne = $this->createPublishedCourse([
            'title' => 'Artificial Intelligence',
            'slug' => 'artificial-intelligence-ordering-test',
            'category' => 'AI & ML',
            'priority' => 1,
        ]);
        $priorityTwo = $this->createPublishedCourse([
            'title' => 'Advanced Data Engineering',
            'slug' => 'advanced-data-engineering-ordering-test',
            'category' => 'Data Engineering',
            'priority' => 2,
        ]);
        $priorityThree = $this->createPublishedCourse([
            'title' => 'Machine Learning',
            'slug' => 'machine-learning-ordering-test',
            'category' => 'AI & ML',
            'priority' => 3,
        ]);

        $response = $this->getJson('/api/courses');
        $response->assertOk();

        $titles = array_column($response->json(), 'title');
        $this->assertEquals(
            ['Artificial Intelligence', 'Advanced Data Engineering', 'Machine Learning'],
            $titles,
            'Public catalog must follow configured priority order, not title order.'
        );

        $this->assertEquals($priorityOne->title, $titles[0], 'Course 1 in the configured order must remain first.');
        $this->assertEquals($priorityTwo->title, $titles[1]); // alphabetically "Advanced Data Engineering" would win, priority must rule
    }

    // 2. The complete configured sequence is preserved end-to-end.
    public function test_public_catalog_preserves_complete_configured_sequence(): void
    {
        $expected = [];
        for ($i = 1; $i <= 6; $i++) {
            $course = $this->createPublishedCourse([
                'title' => "Priority Sequence {$i}",
                'slug' => "priority-sequence-{$i}",
                'priority' => $i,
            ]);
            $expected[] = $course->title;
        }

        $response = $this->getJson('/api/courses');
        $response->assertOk();

        $this->assertEquals($expected, array_column($response->json(), 'title'));
    }

    // 3. Filtering (category/level) reduces the list but never reorders the remaining courses.
    public function test_category_filter_preserves_relative_priority_order(): void
    {
        $this->createPublishedCourse([
            'title' => 'Priority One AI Course',
            'slug' => 'ai-priority-one',
            'category' => 'AI & ML',
            'priority' => 1,
        ]);
        $this->createPublishedCourse([
            'title' => 'Priority Two AI Course',
            'slug' => 'ai-priority-two',
            'category' => 'AI & ML',
            'priority' => 2,
        ]);
        // Outside the filtered category — must be excluded, not reorder the rest.
        $this->createPublishedCourse([
            'title' => 'Priority One Web Course',
            'slug' => 'web-priority-one',
            'category' => 'Full Stack',
            'priority' => 1,
        ]);

        $response = $this->getJson('/api/courses?category=' . urlencode('AI & ML'));
        $response->assertOk();

        $titles = array_column($response->json(), 'title');
        $this->assertEquals(['Priority One AI Course', 'Priority Two AI Course'], $titles);
    }

    // 4. Searching reduces the list but never reorders the remaining courses.
    public function test_search_preserves_relative_priority_order(): void
    {
        $this->createPublishedCourse([
            'title' => 'Alpha Priority Course',
            'slug' => 'alpha-priority-course',
            'priority' => 1,
        ]);
        $this->createPublishedCourse([
            'title' => 'Beta Priority Course',
            'slug' => 'beta-priority-course',
            'priority' => 2,
        ]);
        /* Alphabetically Beta < Gamma; priority must keep them in priority order. */
        $this->createPublishedCourse([
            'title' => 'Gamma Priority Course',
            'slug' => 'gamma-priority-course',
            'priority' => 3,
        ]);

        $response = $this->getJson('/api/courses?search=Priority Course');
        $response->assertOk();

        $titles = array_column($response->json(), 'title');
        $this->assertEquals(['Alpha Priority Course', 'Beta Priority Course', 'Gamma Priority Course'], $titles);
    }

    // 5. Pagination (page param used by the frontend) preserves relative order.
    public function test_page_param_preserves_relative_priority_order(): void
    {
        $expected = [];
        for ($i = 1; $i <= 4; $i++) {
            $course = $this->createPublishedCourse([
                'title' => "Paged Course {$i}",
                'slug' => "paged-course-{$i}",
                'priority' => $i,
            ]);
            $expected[] = $course->title;
        }

        $pageOne = $this->getJson('/api/courses?page=1');
        $pageTwo = $this->getJson('/api/courses?page=2');

        $pageOne->assertOk();
        $pageTwo->assertOk();

        $this->assertEquals($expected, array_column($pageOne->json(), 'title'));
        $this->assertEquals($expected, array_column($pageTwo->json(), 'title'));
    }

    // 6. Courses without an explicit priority fall back deterministically after
    //    explicitly prioritized courses (schema default handling).
    public function test_unprioritized_courses_fall_back_after_prioritized_ones(): void
    {
        $priorityOne = $this->createPublishedCourse([
            'title' => 'Explicitly Prioritized',
            'slug' => 'explicitly-prioritized',
            'priority' => 1,
        ]);
        $implicitDefault = $this->createPublishedCourse([
            'title' => 'No Explicit Priority',
            'slug' => 'no-explicit-priority',
        ]);

        $response = $this->getJson('/api/courses');
        $response->assertOk();

        $titles = array_column($response->json(), 'title');
        $this->assertSame($priorityOne->title, $titles[0]);
        $this->assertContains($implicitDefault->title, $titles);

        $first = $response->json()[0];
        $this->assertEquals(1, $first['priority']);
    }
}