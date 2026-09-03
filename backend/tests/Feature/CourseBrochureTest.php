<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseBrochureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_course_can_be_created_and_updated_with_brochure_pdf(): void
    {
        $media = MediaAsset::create([
            'file_name' => 'Full_Stack_Brochure.pdf',
            'title' => 'Full Stack Brochure',
            'file_path' => 'uploads/media/courses/Full_Stack_Brochure.pdf',
            'disk' => 'public',
            'folder' => 'courses',
            'mime_type' => 'application/pdf',
            'file_size' => 204800,
            'url' => 'http://localhost/storage/uploads/media/courses/Full_Stack_Brochure.pdf',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/courses', [
            'title' => 'Advanced Full Stack Web Development',
            'description' => 'Comprehensive syllabus covering React, Node.js, and Cloud architectures.',
            'category' => 'FULL STACK',
            'instructor' => 'Lead Faculty',
            'duration' => '12 Weeks',
            'difficulty' => 'Intermediate',
            'brochure' => $media->url,
            'brochure_media_id' => $media->id,
            'is_published' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('brochure', $media->url)
            ->assertJsonPath('brochure_media_id', $media->id);

        $courseId = $response->json('id');

        // Test public courses endpoint returns brochure
        $publicRes = $this->getJson("/api/courses/{$courseId}");
        $publicRes->assertStatus(200)
            ->assertJsonPath('brochure', $media->url);

        // Test brochure download endpoint
        $brochureRes = $this->getJson("/api/courses/{$courseId}/brochure");
        $brochureRes->assertStatus(200)
            ->assertJsonPath('brochure_url', $media->url);
    }

    public function test_brochure_endpoint_returns_404_when_unavailable(): void
    {
        $course = Course::create([
            'title' => 'Quantum Computing 101',
            'slug' => 'quantum-computing-101',
            'description' => 'Introduction to quantum circuits.',
            'instructor' => 'Dr. Quantum',
            'duration' => '8 Weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        $response = $this->getJson("/api/courses/{$course->id}/brochure");
        $response->assertStatus(404)
            ->assertJsonPath('message', 'Brochure currently unavailable.');
    }
}
