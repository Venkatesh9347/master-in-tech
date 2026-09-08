<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_fetch_profile(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'name' => 'Ada Lovelace',
            'phone' => '+91 98765 00001',
            'location' => 'Hyderabad, India',
            'bio' => 'Backend engineering enthusiast.',
        ]);
        Sanctum::actingAs($student);

        $res = $this->getJson('/api/student/profile');

        $res->assertStatus(200);
        $this->assertEquals('Ada Lovelace', $res->json('user.name'));
        $this->assertEquals('+91 98765 00001', $res->json('user.phone'));
        $this->assertEquals('Hyderabad, India', $res->json('user.location'));
        $this->assertEquals('Backend engineering enthusiast.', $res->json('user.bio'));
    }

    public function test_student_can_update_profile_fields(): void
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Original Name']);
        Sanctum::actingAs($student);

        $res = $this->putJson('/api/student/profile', [
            'name' => 'Updated Name',
            'phone' => '+91 91234 56789',
            'location' => 'Pune, India',
            'bio' => 'Now into cloud computing.',
        ]);

        $res->assertStatus(200);
        $res->assertJson(['message' => 'Profile updated successfully.']);
        $this->assertEquals('Updated Name', $res->json('user.name'));
        $this->assertEquals('+91 91234 56789', $res->json('user.phone'));
        $this->assertEquals('Pune, India', $res->json('user.location'));
        $this->assertEquals('Now into cloud computing.', $res->json('user.bio'));

        $fresh = $student->fresh();
        $this->assertEquals('Updated Name', $fresh->name);
        $this->assertEquals('Pune, India', $fresh->location);
    }

    public function test_student_can_change_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'password' => bcrypt('oldpassword123'),
        ]);
        Sanctum::actingAs($student);

        $res = $this->putJson('/api/student/profile', [
            'name' => 'Password Changer',
            'password' => 'newpassword456',
        ]);

        $res->assertStatus(200);
        $this->assertTrue(password_verify('newpassword456', $student->fresh()->password));
    }

    public function test_invalid_password_is_rejected(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $res = $this->putJson('/api/student/profile', [
            'name' => 'Short Password',
            'password' => '123',
        ]);

        $res->assertStatus(422);
        $this->assertFalse(password_verify('123', $student->fresh()->password));
    }

    public function test_non_student_cannot_access_student_profile(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        $this->getJson('/api/student/profile')->assertStatus(403);
        $this->putJson('/api/student/profile', ['name' => 'Hack'])->assertStatus(403);
    }
}