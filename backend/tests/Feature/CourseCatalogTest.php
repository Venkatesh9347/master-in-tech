<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Database\Seeders\CourseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourseCatalogSeeder::class);
    }

    // 1. All courses in catalog have valid official levels
    public function test_01_catalog_contains_valid_course_levels(): void
    {
        $courses = Course::where('is_published', true)->get();
        $this->assertGreaterThanOrEqual(20, $courses->count());

        foreach ($courses as $course) {
            $this->assertContains($course->difficulty, ['Basic', 'Intermediate', 'Advanced', 'Beginner']);
            $this->assertNotEmpty($course->category);
            $this->assertNotEmpty($course->description);
            $this->assertNotNull($course->duration);
        }
    }

    // 2. Ethical Hacking course exists with comprehensive curriculum
    public function test_02_ethical_hacking_course_has_comprehensive_curriculum(): void
    {
        $course = Course::where('slug', 'ethical-hacking')->first();
        $this->assertNotNull($course, 'Ethical Hacking course must exist.');
        $this->assertEquals('Cyber Security', $course->category);
        $this->assertEquals('Intermediate', $course->difficulty);

        $sections = $course->sections()->with('lessons')->get();
        $this->assertGreaterThanOrEqual(5, $sections->count());

        $totalLessons = $sections->sum(fn ($s) => $s->lessons->count());
        $this->assertGreaterThanOrEqual(20, $totalLessons);

        // Verify key security topics exist in lessons
        $lessonTitles = $course->lessons()->pluck('title')->implode(' ');
        $this->assertStringContainsString('Reconnaissance', $lessonTitles);
        $this->assertStringContainsString('Nmap', $lessonTitles);
        $this->assertStringContainsString('Burp Suite', $lessonTitles);
        $this->assertStringContainsString('SQL Injection', $lessonTitles);
        $this->assertStringContainsString('Privilege Escalation', $lessonTitles);
        $this->assertStringContainsString('Penetration Testing', $lessonTitles);
    }

    // 3. Category endpoint returns distinct categories with counts
    public function test_03_course_categories_endpoint(): void
    {
        $response = $this->getJson('/api/course-categories');
        $response->assertOk();

        $categories = collect($response->json())->pluck('category')->all();
        $this->assertContains('Cyber Security', $categories);
        $this->assertContains('DATABASE', $categories);
        $this->assertContains('CLOUD COMPUTING', $categories);
        $this->assertContains('DATA SCIENCE', $categories);
        $this->assertContains('SAP', $categories);
        $this->assertContains('ARTIFICIAL INTELLIGENCE', $categories);
    }

    // 4. Course search by keyword works dynamically
    public function test_04_course_search_filter(): void
    {
        $response = $this->getJson('/api/courses?search=Hacking');
        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty($data);
        $titles = array_column($data, 'title');
        $this->assertContains('Ethical Hacking', $titles);
    }

    // 5. Course filter by category works dynamically
    public function test_05_course_category_filter(): void
    {
        $response = $this->getJson('/api/courses?category=DATABASE');
        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty($data);
        foreach ($data as $c) {
            $this->assertEquals('DATABASE', $c['category']);
        }
    }

    // 6. Course show endpoint returns learning objectives, prerequisites, skills, and sections
    public function test_06_course_show_returns_full_metadata(): void
    {
        $response = $this->getJson('/api/courses/ethical-hacking');
        $response->assertOk()
            ->assertJsonPath('title', 'Ethical Hacking')
            ->assertJsonPath('category', 'Cyber Security')
            ->assertJsonPath('difficulty', 'Intermediate');

        $json = $response->json();
        $this->assertArrayHasKey('learning_objectives', $json);
        $this->assertArrayHasKey('prerequisites', $json);
        $this->assertArrayHasKey('skills_gained', $json);
        $this->assertArrayHasKey('sections', $json);
        $this->assertNotEmpty($json['sections']);
    }

    // 7. Enrolled student receives is_enrolled = true
    public function test_07_enrolled_student_receives_is_enrolled_true(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::where('slug', 'ethical-hacking')->first();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/courses/{$course->id}");
        $response->assertOk()
            ->assertJsonPath('is_enrolled', true);
    }

    // 8. Public visitor / non-admin does not see internal price
    public function test_08_pricing_is_hidden_from_public(): void
    {
        $response = $this->getJson('/api/courses/ethical-hacking');
        $response->assertOk();
        $this->assertArrayNotHasKey('internal_price', $response->json());
    }

    // 9. Admin sees internal pricing
    public function test_09_admin_sees_internal_pricing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/courses/ethical-hacking');
        $response->assertOk();
        $this->assertArrayHasKey('internal_price', $response->json());
    }

    // 10. Re-running seeder is duplicate safe (no duplicates created)
    public function test_10_re_running_seeder_is_duplicate_safe(): void
    {
        $initialCount = Course::count();
        $this->seed(CourseCatalogSeeder::class);
        $this->assertEquals($initialCount, Course::count(), 'Seeder must not create duplicate courses.');
    }
}
