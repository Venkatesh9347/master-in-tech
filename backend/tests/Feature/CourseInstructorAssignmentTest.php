<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseInstructorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function coursePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Assigned Ownership Course',
            'description' => 'Ownership test',
            'category' => 'Cloud Computing',
            'instructor' => 'Display Lead',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'price' => 19999,
        ], $overrides);
    }

    public function test_admin_can_assign_a_specific_tutor_as_course_instructor(): void
    {
        Sanctum::actingAs($this->admin());
        $tutor = User::factory()->create(['role' => 'tutor', 'name' => 'Owning Faculty']);

        $res = $this->postJson('/api/courses', $this->coursePayload(['instructor_id' => $tutor->id]));

        $res->assertStatus(201);
        $this->assertEquals($tutor->id, $res->json('instructor_id'));
        $course = Course::findOrFail($res->json('id'));
        $this->assertSame($tutor->id, $course->instructor_id);
    }

    public function test_admin_can_assign_a_faculty_account_as_course_instructor(): void
    {
        Sanctum::actingAs($this->admin());
        $faculty = User::factory()->create(['role' => 'faculty', 'name' => 'Faculty Lead']);

        $res = $this->postJson('/api/courses', $this->coursePayload(['instructor_id' => $faculty->id]));

        $res->assertStatus(201);
        $this->assertEquals($faculty->id, $res->json('instructor_id'));
    }

    public function test_course_without_instructor_id_defaults_to_creating_admin(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/courses', $this->coursePayload());

        $res->assertStatus(201);
        $this->assertEquals($admin->id, $res->json('instructor_id'));
    }

    public function test_course_cannot_be_assigned_to_a_student_account(): void
    {
        Sanctum::actingAs($this->admin());
        $student = User::factory()->create(['role' => 'student']);

        $res = $this->postJson('/api/courses', $this->coursePayload(['instructor_id' => $student->id]));

        $res->assertStatus(422);
        $this->assertSame(
            'The assigned instructor must be a tutor, faculty, or admin account.',
            $res->json('message')
        );
    }

    public function test_update_preserves_assigned_instructor(): void
    {
        Sanctum::actingAs($this->admin());
        $tutor = User::factory()->create(['role' => 'tutor', 'name' => 'Owning Faculty']);
        $course = Course::create([
            'title' => 'Pre Existing Course',
            'slug' => 'pre-existing-course-' . uniqid(),
            'description' => 'Seed',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'price' => 9999,
            'is_published' => false,
        ]);

        $res = $this->putJson("/api/courses/{$course->id}", [
            'title' => 'Renamed Course',
            'is_published' => true,
        ]);

        $res->assertStatus(200);
        $this->assertEquals($tutor->id, $course->fresh()->instructor_id);
        $this->assertEquals('Renamed Course', $course->fresh()->title);
    }

    public function test_assigned_tutor_sees_the_course_in_their_panel(): void
    {
        $owner = User::factory()->create(['role' => 'tutor', 'name' => 'Owner Tutor']);
        Sanctum::actingAs($this->admin());
        $created = $this->postJson('/api/courses', $this->coursePayload(['instructor_id' => $owner->id]));
        $created->assertStatus(201);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/tutor/courses');

        $res->assertStatus(200);
        $res->assertJsonFragment(['id' => $created->json('id')]);
    }

    public function test_other_tutor_does_not_see_assigned_course(): void
    {
        $owner = User::factory()->create(['role' => 'tutor', 'name' => 'Owner Tutor']);
        $other = User::factory()->create(['role' => 'tutor', 'name' => 'Other Tutor']);
        Sanctum::actingAs($this->admin());
        $created = $this->postJson('/api/courses', $this->coursePayload(['instructor_id' => $owner->id]));
        $created->assertStatus(201);

        Sanctum::actingAs($other);
        $res = $this->getJson('/api/tutor/courses');

        $res->assertStatus(200);
        $res->assertJsonMissing(['id' => $created->json('id')]);
    }
}