<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\CourseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseLevelSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourseCatalogSeeder::class);
    }

    public function test_api_filters_courses_by_basic_intermediate_and_advanced_levels(): void
    {
        $responseAll = $this->getJson('/api/courses');
        $responseAll->assertOk();

        // Level = Basic
        $responseBasic = $this->getJson('/api/courses?level=basic');
        $responseBasic->assertOk();
        $basicData = $responseBasic->json();
        $this->assertNotEmpty($basicData);
        foreach ($basicData as $c) {
            $this->assertContains(strtolower($c['difficulty']), ['basic', 'beginner']);
        }

        // Level = Intermediate
        $responseInter = $this->getJson('/api/courses?level=intermediate');
        $responseInter->assertOk();
        $interData = $responseInter->json();
        $this->assertNotEmpty($interData);
        foreach ($interData as $c) {
            $this->assertEquals('intermediate', strtolower($c['difficulty']));
        }

        // Level = Advanced
        $responseAdv = $this->getJson('/api/courses?level=advanced');
        $responseAdv->assertOk();
        $advData = $responseAdv->json();
        $this->assertNotEmpty($advData);
        foreach ($advData as $c) {
            $this->assertEquals('advanced', strtolower($c['difficulty']));
        }
    }

    public function test_api_filters_courses_by_category_and_level_combined(): void
    {
        $response = $this->getJson('/api/courses?category=cyber-security&level=intermediate');
        $response->assertOk();
        $courses = $response->json();

        $this->assertNotEmpty($courses);
        $titles = array_column($courses, 'title');
        $this->assertContains('Ethical Hacking', $titles);

        foreach ($courses as $c) {
            $this->assertEquals('cyber security', strtolower($c['category']));
            $this->assertEquals('intermediate', strtolower($c['difficulty']));
        }
    }

    public function test_ethical_hacking_learning_path_progression(): void
    {
        $csFundamentals = Course::where('slug', 'cyber-security-fundamentals')->first();
        $this->assertNotNull($csFundamentals);
        $this->assertEquals('Basic', $csFundamentals->difficulty);

        $ethicalHacking = Course::where('slug', 'ethical-hacking')->first();
        $this->assertNotNull($ethicalHacking);
        $this->assertEquals('Intermediate', $ethicalHacking->difficulty);

        $advPentest = Course::where('slug', 'advanced-penetration-testing')->first();
        $this->assertNotNull($advPentest);
        $this->assertEquals('Advanced', $advPentest->difficulty);

        $redTeaming = Course::where('slug', 'red-teaming')->first();
        $this->assertNotNull($redTeaming);
        $this->assertEquals('Advanced', $redTeaming->difficulty);
    }

    public function test_course_details_returns_metadata_and_curriculum(): void
    {
        $response = $this->getJson('/api/courses/ethical-hacking');
        $response->assertOk()
            ->assertJsonStructure([
                'id',
                'title',
                'slug',
                'description',
                'full_description',
                'difficulty',
                'category',
                'prerequisites',
                'learning_objectives',
                'skills_gained',
                'sections',
            ]);

        $data = $response->json();
        $this->assertEquals('Intermediate', $data['difficulty']);
        $this->assertNotEmpty($data['sections']);
        $this->assertNotEmpty($data['prerequisites']);
        $this->assertNotEmpty($data['learning_objectives']);
    }

    public function test_all_14_canonical_categories_exist(): void
    {
        $canonicalCategories = [
            'Cyber Security',
            'DATABASE',
            'CLOUD COMPUTING',
            'DATA ENGINEERING',
            'DATA ANALYST',
            'DATA SCIENCE',
            'SAP',
            'ARTIFICIAL INTELLIGENCE',
            'FULL STACK',
            'Marketing & Business',
            'Healthcare & Life Sciences',
            'Quality Assurance & Testing',
            'Mobile Engineering',
            'Design & Creative',
        ];

        foreach ($canonicalCategories as $category) {
            $count = Course::where('category', $category)->where('is_published', true)->count();
            $this->assertGreaterThan(0, $count, "Category {$category} should have published courses");
        }
    }

    public function test_admin_can_create_and_update_course_with_levels(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $createRes = $this->actingAs($admin, 'sanctum')->postJson('/api/courses', [
            'title' => 'Test SRE Advanced Architecture Course',
            'description' => 'Test description for SRE advanced course',
            'category' => 'CLOUD COMPUTING',
            'difficulty' => 'Advanced',
            'duration' => '8 Weeks',
            'instructor' => 'Test Admin',
            'is_published' => true,
        ]);

        $createRes->assertCreated();
        $courseId = $createRes->json('id');
        $this->assertEquals('Advanced', $createRes->json('difficulty'));

        $updateRes = $this->actingAs($admin, 'sanctum')->putJson("/api/courses/{$courseId}", [
            'difficulty' => 'Basic',
            'title' => 'Test SRE Basic Fundamentals Course',
        ]);

        $updateRes->assertOk();
        $this->assertEquals('Basic', $updateRes->json('difficulty'));

        // Clean up test course
        Course::find($courseId)?->delete();
    }
}
