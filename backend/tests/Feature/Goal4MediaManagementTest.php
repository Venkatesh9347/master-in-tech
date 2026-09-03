<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Goal4MediaManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_list_and_filter_media_by_folder(): void
    {
        MediaAsset::create([
            'file_name' => 'course-banner.jpg',
            'title' => 'Course Banner Graphic',
            'file_path' => 'uploads/media/courses/course-banner.jpg',
            'disk' => 'public',
            'folder' => 'courses',
            'mime_type' => 'image/jpeg',
            'file_size' => 102400,
            'url' => 'http://localhost/storage/uploads/media/courses/course-banner.jpg',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        MediaAsset::create([
            'file_name' => 'instructor-headshot.png',
            'title' => 'Instructor Headshot',
            'file_path' => 'uploads/media/instructors/instructor-headshot.png',
            'disk' => 'public',
            'folder' => 'instructors',
            'mime_type' => 'image/png',
            'file_size' => 204800,
            'url' => 'http://localhost/storage/uploads/media/instructors/instructor-headshot.png',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        // Filter by 'courses' folder
        $response = $this->actingAs($this->admin)->getJson('/api/admin/media?folder=courses');
        $response->assertStatus(200)
            ->assertJsonFragment(['folder' => 'courses'])
            ->assertJsonMissing(['folder' => 'instructors']);

        // Filter by 'instructors' folder
        $response2 = $this->actingAs($this->admin)->getJson('/api/admin/media?folder=instructors');
        $response2->assertStatus(200)
            ->assertJsonFragment(['folder' => 'instructors'])
            ->assertJsonMissing(['folder' => 'courses']);
    }

    public function test_admin_can_upload_media_with_metadata_and_folder(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('ai_course_cover.jpg', 800, 600);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/media', [
            'file' => $file,
            'title' => 'AI Mastery Card Cover',
            'alt_text' => 'Artificial Intelligence deep learning diagram',
            'folder' => 'courses',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('media.title', 'AI Mastery Card Cover')
            ->assertJsonPath('media.folder', 'courses')
            ->assertJsonPath('media.alt_text', 'Artificial Intelligence deep learning diagram');

        $this->assertDatabaseHas('media_assets', [
            'title' => 'AI Mastery Card Cover',
            'folder' => 'courses',
        ]);
    }

    public function test_admin_can_update_media_metadata(): void
    {
        $media = MediaAsset::create([
            'file_name' => 'logo.png',
            'title' => 'Original Logo',
            'file_path' => 'uploads/media/branding/logo.png',
            'disk' => 'public',
            'folder' => 'branding',
            'mime_type' => 'image/png',
            'file_size' => 50000,
            'url' => 'http://localhost/storage/uploads/media/branding/logo.png',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/media/{$media->id}", [
            'title' => 'Updated MasterInTech Vector Logo',
            'alt_text' => 'MasterInTech Official Logo Symbol',
            'folder' => 'branding',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('media.title', 'Updated MasterInTech Vector Logo')
            ->assertJsonPath('media.alt_text', 'MasterInTech Official Logo Symbol');

        $this->assertDatabaseHas('media_assets', [
            'id' => $media->id,
            'title' => 'Updated MasterInTech Vector Logo',
        ]);
    }

    public function test_admin_can_replace_media_file_on_disk(): void
    {
        Storage::fake('public');

        $originalFile = UploadedFile::fake()->image('old_banner.jpg');
        $path = $originalFile->store('uploads/media/courses', 'public');

        $media = MediaAsset::create([
            'file_name' => 'old_banner.jpg',
            'title' => 'Course Banner',
            'file_path' => $path,
            'disk' => 'public',
            'folder' => 'courses',
            'mime_type' => 'image/jpeg',
            'file_size' => 10000,
            'url' => 'http://localhost/storage/' . $path,
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $newFile = UploadedFile::fake()->image('new_banner.png');

        $response = $this->actingAs($this->admin)->postJson("/api/admin/media/{$media->id}/replace", [
            'file' => $newFile,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('media_assets', [
            'id' => $media->id,
            'mime_type' => 'image/png',
        ]);
    }

    public function test_admin_can_delete_media_asset(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('to_delete.jpg');
        $path = $file->store('uploads/media/general', 'public');

        $media = MediaAsset::create([
            'file_name' => 'to_delete.jpg',
            'title' => 'To Delete',
            'file_path' => $path,
            'disk' => 'public',
            'folder' => 'general',
            'mime_type' => 'image/jpeg',
            'file_size' => 10000,
            'url' => 'http://localhost/storage/' . $path,
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/media/{$media->id}");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('media_assets', ['id' => $media->id]);
    }

    public function test_admin_can_create_and_update_course_with_thumbnail_and_banner(): void
    {
        $media = MediaAsset::create([
            'file_name' => 'ai_thumb.jpg',
            'title' => 'AI Thumbnail',
            'file_path' => 'uploads/media/courses/ai_thumb.jpg',
            'disk' => 'public',
            'folder' => 'courses',
            'url' => 'http://localhost/storage/uploads/media/courses/ai_thumb.jpg',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/courses', [
            'title' => 'Generative AI & LLM Systems',
            'description' => 'Build advanced AI agents with LangChain, LlamaIndex, and fine-tuning.',
            'category' => 'Artificial Intelligence',
            'instructor' => 'Dr. Andrew Miller',
            'duration' => '12 Weeks',
            'difficulty' => 'Advanced',
            'thumbnail' => $media->url,
            'banner' => 'http://localhost/storage/uploads/media/courses/ai_banner.jpg',
            'media_id' => $media->id,
            'status' => 'published',
            'is_published' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('thumbnail', $media->url)
            ->assertJsonPath('banner', 'http://localhost/storage/uploads/media/courses/ai_banner.jpg')
            ->assertJsonPath('media_id', $media->id);

        $courseId = $response->json('id');

        // Public course API returns thumbnail and banner
        $publicRes = $this->getJson("/api/courses/{$courseId}");
        $publicRes->assertStatus(200)
            ->assertJsonPath('thumbnail', $media->url)
            ->assertJsonPath('banner', 'http://localhost/storage/uploads/media/courses/ai_banner.jpg');
    }

    public function test_unauthenticated_and_student_users_cannot_manage_media(): void
    {
        $this->getJson('/api/admin/media')->assertStatus(401);
        $this->actingAs($this->student)->getJson('/api/admin/media')->assertStatus(403);
    }
}
