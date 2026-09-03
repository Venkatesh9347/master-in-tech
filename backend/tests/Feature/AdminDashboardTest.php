<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_dashboard_and_receives_complete_operational_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Operations Admin']);
        $tutor = User::factory()->create(['role' => 'tutor', 'name' => 'Lead Tutor']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Active Student']);

        $course = Course::create([
            'title' => 'Cloud & DevOps Mastery',
            'slug' => 'cloud-devops-mastery',
            'description' => 'AWS & Docker',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '10 weeks',
            'difficulty' => 'Intermediate',
            'price' => 29999,
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
            'status' => 'active',
        ]);

        Enquiry::create([
            'name' => 'Demo Candidate',
            'email' => 'demo@example.com',
            'phone' => '9876543210',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/admin/dashboard');

        $res->assertStatus(200)
            ->assertJsonStructure([
                'statistics' => [
                    'total_students',
                    'active_students',
                    'total_tutors',
                    'active_tutors',
                    'total_courses',
                    'published_courses',
                    'new_enquiries',
                    'todays_demos',
                    'pending_admissions',
                    'active_enrollments',
                ],
                'admissions_pipeline',
                'recent_enquiries',
                'todays_demos',
                'recent_admissions',
                'courses_overview',
                'student_activity',
                'tutor_activity',
                'system_activity',
            ]);

        $this->assertEquals(1, $res->json('statistics.total_students'));
        $this->assertEquals(1, $res->json('statistics.active_students'));
        $this->assertEquals(1, $res->json('statistics.total_tutors'));
        $this->assertEquals(1, $res->json('statistics.total_courses'));
        $this->assertEquals(1, $res->json('statistics.new_enquiries'));
    }

    public function test_student_cannot_access_admin_dashboard_api(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $this->getJson('/api/admin/dashboard')->assertStatus(403);
    }

    public function test_tutor_cannot_access_admin_dashboard_api(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        $this->getJson('/api/admin/dashboard')->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_admin_dashboard_api(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }
}
