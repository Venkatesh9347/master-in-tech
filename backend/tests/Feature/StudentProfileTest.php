<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_update_own_profile(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'phone' => '+91 11111 11111',
        ]);
        Sanctum::actingAs($student);

        $response = $this->putJson('/api/profile', [
            'name' => 'Updated Student Name',
            'phone' => '+91 99999 88888',
            'location' => 'Mumbai, India',
            'bio' => 'My updated bio.',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Profile updated successfully.',
            ]);

        $payload = $response->json('user');
        $this->assertEquals('Updated Student Name', $payload['name']);
        $this->assertEquals('+91 99999 88888', $payload['phone']);
        $this->assertEquals('Mumbai, India', $payload['location']);
        $this->assertEquals('My updated bio.', $payload['bio']);
    }

    public function test_requires_current_password_when_setting_new_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'password' => bcrypt('correct-password'),
        ]);
        Sanctum::actingAs($student);

        $response = $this->putJson('/api/profile', [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            // no current_password
        ]);

        $response->assertStatus(422);
    }

    public function test_rejects_incorrect_current_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'password' => bcrypt('correct-password'),
        ]);
        Sanctum::actingAs($student);

        $response = $this->putJson('/api/profile', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_updates_password_with_correct_current_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'password' => bcrypt('correct-password'),
        ]);
        Sanctum::actingAs($student);

        $response = $this->putJson('/api/profile', [
            'current_password' => 'correct-password',
            'password' => 'new-secure-pass',
            'password_confirmation' => 'new-secure-pass',
        ]);

        $response->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(
            'new-secure-pass',
            $student->fresh()->password
        ));
    }

    public function test_student_cannot_change_role(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
        ]);
        Sanctum::actingAs($student);

        $response = $this->putJson('/api/profile', [
            'role' => 'admin',
            'name' => 'Still Student',
        ]);

        $response->assertOk();
        $this->assertEquals('student', $student->fresh()->role);
    }

    public function test_profile_endpoint_requires_authentication(): void
    {
        $response = $this->putJson('/api/profile', ['name' => 'Anonymous']);

        $response->assertStatus(401);
    }

    public function test_student_can_upload_avatar_image(): void
    {
        Storage::fake('public');
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $response = $this->put('/api/profile', [
            'name' => 'Avatar Student',
            'avatar' => UploadedFile::fake()->image('photo.jpg', 200, 200),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $avatarUrl = $response->json('user.avatar');
        $this->assertNotNull($avatarUrl);
        $this->assertStringStartsWith('/storage/avatars/', $avatarUrl);

        Storage::disk('public')->assertExists(str_replace('/storage/', '', $avatarUrl));
    }

    public function test_student_can_set_avatar_url_and_clear_it(): void
    {
        $student = User::factory()->create(['role' => 'student', 'avatar' => 'https://example.com/pic.png']);
        Sanctum::actingAs($student);

        $set = $this->putJson('/api/profile', ['avatar' => 'https://ui-avatars.com/api/?name=Student']);
        $set->assertOk()->assertJsonPath('user.avatar', 'https://ui-avatars.com/api/?name=Student');

        $clear = $this->putJson('/api/profile', ['avatar' => null]);
        $clear->assertOk()->assertJsonPath('user.avatar', null);
    }

    public function test_student_avatar_upload_rejects_non_image_file(): void
    {
        Storage::fake('public');
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $response = $this->put('/api/profile', [
            'avatar' => UploadedFile::fake()->create('notes.txt', 100),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }
}
