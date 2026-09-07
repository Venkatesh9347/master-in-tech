<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Batch3ApiPolishTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => strtoupper(Str::random(3)),
            'description' => 'Catalog course description.',
            'category' => 'Software Engineering',
            'instructor' => 'Catalog Instructor',
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ], $attributes));
    }

    // ---- API-006: deprecated /api/public/courses stays backward compatible --

    public function test_deprecated_public_courses_endpoint_remains_operational(): void
    {
        $published = $this->createCourse(['title' => 'Public Visible Course']);

        $response = $this->getJson('/api/public/courses');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('id', $published->id));
    }

    public function test_deprecated_public_course_detail_remains_operational_by_slug_and_id(): void
    {
        $course = $this->createCourse(['title' => 'Detail Visible Course']);

        $this->getJson("/api/public/courses/{$course->id}")->assertOk();
        $this->getJson("/api/public/courses/{$course->slug}")->assertOk();
    }

    public function test_unpublished_course_is_not_exposed_by_either_endpoint(): void
    {
        $unpublished = $this->createCourse([
            'title' => 'Hidden Draft Course',
            'is_published' => false,
        ]);

        $legacy = $this->getJson('/api/public/courses');
        $legacy->assertOk();
        $this->assertFalse(collect($legacy->json())->contains('title', $unpublished->title));

        $legacyDetail = $this->getJson("/api/public/courses/{$unpublished->id}");
        $legacyDetail->assertNotFound();

        $catalog = $this->getJson('/api/courses');
        $catalog->assertOk();
        $this->assertFalse(collect($catalog->json())->contains('title', $unpublished->title));
    }

    public function test_catalog_endpoint_supports_same_filters_as_deprecated_endpoint(): void
    {
        $react = $this->createCourse([
            'title' => 'React Advanced',
            'category' => 'Frontend',
            'difficulty' => 'Advanced',
        ]);
        $python = $this->createCourse([
            'title' => 'Python Basics',
            'category' => 'Data Science',
            'difficulty' => 'Beginner',
        ]);

        // search
        $bySearch = $this->getJson('/api/courses?search=React');
        $bySearch->assertOk();
        $this->assertTrue(collect($bySearch->json())->contains('id', $react->id));
        $this->assertFalse(collect($bySearch->json())->contains('id', $python->id));

        // category
        $byCategory = $this->getJson('/api/courses?category=Data Science');
        $byCategory->assertOk();
        $this->assertTrue(collect($byCategory->json())->contains('id', $python->id));
        $this->assertFalse(collect($byCategory->json())->contains('id', $react->id));

        // difficulty
        $byDifficulty = $this->getJson('/api/courses?difficulty=Advanced');
        $byDifficulty->assertOk();
        $this->assertTrue(collect($byDifficulty->json())->contains('id', $react->id));
        $this->assertFalse(collect($byDifficulty->json())->contains('id', $python->id));
    }

    public function test_catalog_endpoint_is_functional_superset_of_deprecated_endpoint(): void
    {
        $this->createCourse(['title' => 'Superset Course']);

        $legacy = $this->getJson('/api/public/courses')->assertOk()->json();
        $catalog = $this->getJson('/api/courses')->assertOk()->json();

        $legacyIds = collect($legacy)->pluck('id')->all();
        $catalogIds = collect($catalog)->pluck('id')->all();

        // Every course visible on the deprecated endpoint is visible in the catalog.
        foreach ($legacyIds as $id) {
            $this->assertContains($id, $catalogIds);
        }
    }
}