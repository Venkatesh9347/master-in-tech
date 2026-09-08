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

    private function admin()
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function tutor()
    {
        return User::factory()->create(['role' => 'tutor']);
    }

    private function faculty()
    {
        return User::factory()->create(['role' => 'faculty']);
    }

    private function student()
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function coursePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Test Course Title',
            'description' => 'Test course description',
            'category' => 'Full Stack Development',
            'instructor' => 'Test Instructor',
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'price' => 10000,
        ], $overrides);
    }

    public function test_admin_can_assign_valid_tutor_on_course_creation(): void
    {
        $admin = $this->admin();
        $tutor = $this->tutor();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/courses', $this->coursePayload([
            'instructor_id' => $tutor->id,
        ]));

        $response->assertStatus(201)
            ->assertJson([
                'instructor_id' => $tutor->id,
            ]);

        $this->assertDatabaseHas('courses', [
            'id' => $response->json('id'),
            'instructor_id' => $tutor->id,
        ]);
    }

    public function test_admin_can_assign_valid_faculty_on_course_creation(): void
    {
        $admin = $this->admin();
        $faculty = $this->faculty();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/courses', $this->coursePayload([
            'instructor_id' => $faculty->id,
        ]));

        $response->assertStatus(201)
            ->assertJson([
                'instructor_id' => $faculty->id,
            ]);
    }

    public function test_admin_cannot_assign_student_as_instructor(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/courses', $this->coursePayload([
            'instructor_id' => $student->id,
        ]));

        $response->assertStatus(422);
    }

    public function test_admin_cannot_assign_nonexistent_instructor(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/courses', $this->coursePayload([
            'instructor_id' => 99999,
        ]));

        $response->assertStatus(422);
    }

    public function test_creator_fallback_applies_when_no_instructor_supplied(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/courses', $this->coursePayload());

        $response->assertStatus(201)
            ->assertJson([
                'instructor_id' => $admin->id,
            ]);
    }

    public function test_admin_can_reassign_tutor_on_update(): void
    {
        $admin = $this->admin();
        $tutor1 = $this->tutor();
        $tutor2 = $this->tutor();

        $course = Course::create([
            'title' => 'Original Course',
            'slug' => 'original-course',
            'description' => 'Desc',
            'category' => 'Dev',
            'instructor' => $tutor1->name,
            'instructor_id' => $tutor1->id,
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/courses/{$course->id}", [
            'instructor_id' => $tutor2->id,
        ]);

        $response->assertOk()
            ->assertJson([
                'instructor_id' => $tutor2->id,
            ]);
    }

    public function test_admin_cannot_reassign_to_student_on_update(): void
    {
        $admin = $this->admin();
        $tutor = $this->tutor();
        $student = $this->student();

        $course = Course::create([
            'title' => 'Original Course',
            'slug' => 'original-course-2',
            'description' => 'Desc',
            'category' => 'Dev',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/courses/{$course->id}", [
            'instructor_id' => $student->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_assigned_tutor_sees_course_in_tutor_list(): void
    {
        $tutor = $this->tutor();
        $otherTutor = $this->tutor();

        Course::create([
            'title' => 'Tutor Assigned Course',
            'slug' => 'tutor-assigned-course',
            'description' => 'Desc',
            'category' => 'Dev',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        Course::create([
            'title' => 'Other Tutor Course',
            'slug' => 'other-tutor-course',
            'description' => 'Desc',
            'category' => 'Dev',
            'instructor' => $otherTutor->name,
            'instructor_id' => $otherTutor->id,
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/tutor/courses');

        $response->assertOk();
        $titles = collect($response->json())->pluck('title')->all();
        $this->assertContains('Tutor Assigned Course', $titles);
        $this->assertNotContains('Other Tutor Course', $titles);
    }

    public function test_other_tutor_does_not_see_course(): void
    {
        $tutor = $this->tutor();
        $other = $this->tutor();

        Course::create([
            'title' => 'Private Course',
            'slug' => 'private-course',
            'description' => 'Desc',
            'category' => 'Dev',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        Sanctum::actingAs($other);

        $response = $this->getJson('/api/tutor/courses');

        $response->assertOk();
        $titles = collect($response->json())->pluck('title')->all();
        $this->assertNotContains('Private Course', $titles);
    }

    public function test_admin_tutor_endpoint_lists_eligible_instructors_only(): void
    {
        $admin = $this->admin();
        $tutor = $this->tutor();
        $faculty = $this->faculty();
        $student = $this->student();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users/tutors');

        $response->assertOk();
        $roles = collect($response->json())->pluck('role')->unique()->all();
        $this->assertNotContains('student', $roles);
        $this->assertContains('tutor', $roles);
        $this->assertContains('faculty', $roles);
    }
}
