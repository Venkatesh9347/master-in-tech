<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseCatalogGuardAndFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(bool $published, float $price = 100.0): Course
    {
        return Course::create([
            'title' => 'Guard Course',
            'slug' => 'guard-course-' . (string) count(Course::all()),
            'description' => 'Description',
            'category' => 'Data Science',
            'instructor' => 'Test Faculty',
            'price' => $price,
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => $published,
        ]);
    }

    public function test_draft_course_detail_is_hidden_from_anonymous_visitors(): void
    {
        $course = $this->makeCourse(false);

        $this->getJson("/api/courses/{$course->id}")
            ->assertNotFound();

        $this->getJson("/api/courses/{$course->slug}")
            ->assertNotFound();
    }

    public function test_draft_course_detail_is_hidden_from_students(): void
    {
        $course = $this->makeCourse(false);
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}")
            ->assertNotFound();
    }

    public function test_draft_course_detail_is_visible_to_admin(): void
    {
        $course = $this->makeCourse(false);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('id', $course->id);
    }

    public function test_draft_course_detail_is_visible_to_its_instructor(): void
    {
        $instructor = User::factory()->create(['role' => 'tutor']);
        $course = Course::create([
            'title' => 'Instructor Draft',
            'slug' => 'instructor-draft-' . uniqid(),
            'description' => 'Description',
            'category' => 'Data Science',
            'instructor' => $instructor->name,
            'instructor_id' => $instructor->id,
            'price' => 100.0,
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => false,
        ]);

        $this->actingAs($instructor, 'sanctum')
            ->getJson("/api/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('id', $course->id);
    }

    public function test_published_course_detail_is_public(): void
    {
        $course = $this->makeCourse(true);

        $this->getJson("/api/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('id', $course->id);
    }

    public function test_catalog_price_filter_is_applied_server_side(): void
    {
        $this->makeCourse(true, 250.0);
        $this->makeCourse(true, 750.0);
        $admin = User::factory()->create(['role' => 'admin']);

        $cheap = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/courses?min_price=0&max_price=500')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($cheap);
        $this->assertTrue(collect($cheap)->every(fn ($c) => false === isset($c['price']) || (float) $c['price'] <= 500.0));
    }

    public function test_catalog_price_filter_excludes_out_of_range(): void
    {
        $this->makeCourse(true, 250.0);
        $this->makeCourse(true, 750.0);
        $admin = User::factory()->create(['role' => 'admin']);

        $expensive = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/courses?min_price=600')
            ->assertOk()
            ->json();

        $this->assertSame(1, count($expensive));
    }
}