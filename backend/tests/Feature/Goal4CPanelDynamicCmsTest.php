<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Faq;
use App\Models\HomeSection;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\NavigationItem;
use App\Models\Resource;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\WebsiteSetting;
use Database\Seeders\CmsContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Goal4CPanelDynamicCmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsContentSeeder::class);
    }

    public function test_public_home_endpoint_returns_dynamic_database_content(): void
    {
        $response = $this->getJson('/api/public/home');

        $response->assertOk()
            ->assertJsonStructure([
                'sections',
                'categories',
                'featured_courses',
                'learning_paths',
                'instructors',
                'testimonials',
                'events',
                'faqs',
                'settings',
            ]);

        $this->assertNotEmpty($response->json('categories'));
        $this->assertNotEmpty($response->json('sections'));
    }

    public function test_public_endpoints_return_active_data(): void
    {
        // Categories
        $this->getJson('/api/public/categories')->assertOk();

        // Instructors
        $this->getJson('/api/public/instructors')->assertOk();

        // Learning Paths
        $this->getJson('/api/public/learning-paths')->assertOk();

        // Testimonials
        $this->getJson('/api/public/testimonials')->assertOk();

        // FAQs
        $this->getJson('/api/public/faqs')->assertOk();

        // Resources
        $this->getJson('/api/public/resources')->assertOk();

        // Settings
        $settingsRes = $this->getJson('/api/public/settings');
        $settingsRes->assertOk();
        $this->assertArrayNotHasKey('db_password', $settingsRes->json());
        $this->assertArrayNotHasKey('mail_password', $settingsRes->json());

        // Navigation
        $this->getJson('/api/public/navigation')->assertOk()
            ->assertJsonStructure(['header', 'footer_learning', 'footer_support']);
    }

    public function test_admin_category_management_crud(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 1. Create Category
        $createRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/categories', [
            'name' => 'Generative AI & LLM Systems',
            'icon' => '✨',
            'description' => 'Advanced LLM prompting, embeddings, and fine-tuning',
            'sort_order' => 20,
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('category.name', 'Generative AI & LLM Systems');

        $categoryId = $createRes->json('category.id');

        // 2. Update Category
        $updateRes = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/categories/{$categoryId}", [
            'name' => 'Generative AI & Agentic Systems',
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('category.name', 'Generative AI & Agentic Systems');

        // 3. Reorder Categories
        $reorderRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/categories/reorder', [
            'orders' => [
                ['id' => $categoryId, 'sort_order' => 1],
            ],
        ]);
        $reorderRes->assertOk();
        $this->assertEquals(1, CourseCategory::find($categoryId)->sort_order);

        // 4. Delete Category
        $delRes = $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/categories/{$categoryId}");
        $delRes->assertOk();
        $this->assertDatabaseMissing('course_categories', ['id' => $categoryId]);
    }

    public function test_course_publishing_immediately_controls_public_visibility(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 1. Create Course
        $createRes = $this->actingAs($admin, 'sanctum')->postJson('/api/courses', [
            'title' => 'Quantum Computing Fundamentals',
            'description' => 'Intro to quantum qubits and algorithms.',
            'category' => 'Artificial Intelligence',
            'instructor' => 'Dr. Quantum',
            'duration' => '6 Weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
            'status' => 'published',
        ]);
        $createRes->assertStatus(201);
        $courseId = $createRes->json('id');

        // Verify visible publicly
        $pubRes1 = $this->getJson('/api/public/courses');
        $pubRes1->assertOk();
        $this->assertTrue(collect($pubRes1->json())->contains('id', $courseId));

        // 2. Admin unpublishes course
        $unpubRes = $this->actingAs($admin, 'sanctum')->putJson("/api/courses/{$courseId}", [
            'is_published' => false,
            'status' => 'draft',
        ]);
        $unpubRes->assertOk();

        // Verify NO LONGER visible publicly
        $pubRes2 = $this->getJson('/api/public/courses');
        $this->assertFalse(collect($pubRes2->json())->contains('id', $courseId));

        // 3. Admin republishes course
        $this->actingAs($admin, 'sanctum')->putJson("/api/courses/{$courseId}", [
            'is_published' => true,
            'status' => 'published',
        ])->assertOk();

        // Verify reappears publicly
        $pubRes3 = $this->getJson('/api/public/courses');
        $this->assertTrue(collect($pubRes3->json())->contains('id', $courseId));
    }

    public function test_admin_home_cms_section_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $hero = HomeSection::where('section_key', 'hero')->first();
        $this->assertNotNull($hero);

        // 1. Update Hero Title & Content
        $updateRes = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/home-sections/{$hero->id}", [
            'title' => 'Custom C-Panel Hero Title for 2026',
            'subtitle' => 'Live customized subtitle updated by administrator',
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('section.title', 'Custom C-Panel Hero Title for 2026');

        // 2. Toggle Section Visibility
        $toggleRes = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/home-sections/{$hero->id}/toggle");
        $toggleRes->assertOk();
        $this->assertFalse(HomeSection::find($hero->id)->is_enabled);

        // Verify disabled section is excluded from public home
        $publicHome = $this->getJson('/api/public/home')->json('sections');
        $this->assertFalse(collect($publicHome)->contains('section_key', 'hero'));
    }

    public function test_admin_faculty_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/instructors', [
            'name' => 'Prof. Alan Turing',
            'designation' => 'Distinguished Computer Scientist',
            'company' => 'Cambridge',
            'bio' => 'Pioneer of theoretical computer science and artificial intelligence.',
            'avatar' => '👨‍🏫',
            'rating' => '5.0 ★',
            'display_order' => 10,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('instructor.name', 'Prof. Alan Turing');

        $instructorId = $res->json('instructor.id');
        $this->assertDatabaseHas('instructors', ['id' => $instructorId]);

        // Delete instructor
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/instructors/{$instructorId}")
            ->assertOk();
        $this->assertDatabaseMissing('instructors', ['id' => $instructorId]);
    }

    public function test_admin_settings_batch_update_and_audit_logging(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $res = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
            'settings' => [
                ['key' => 'site_name', 'value' => 'MasterInTech Global Academy', 'group' => 'general'],
                ['key' => 'contact_phone', 'value' => '+91 99999 11111', 'group' => 'contact'],
            ],
        ]);

        $res->assertOk();
        $this->assertEquals('MasterInTech Global Academy', WebsiteSetting::get('site_name'));
        $this->assertEquals('+91 99999 11111', WebsiteSetting::get('contact_phone'));

        // Verify Audit Log recorded
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated_website_settings',
        ]);
    }
}
