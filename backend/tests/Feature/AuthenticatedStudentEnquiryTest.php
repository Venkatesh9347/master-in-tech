<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticatedStudentEnquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_visitor_enquiry_requires_details_and_sets_user_id_to_null(): void
    {
        $course = Course::create([
            'title' => 'Cloud & DevOps Engineering',
            'slug' => 'cloud-devops',
            'description' => 'DevOps mastery',
            'instructor' => 'Rajesh Kumar',
            'duration' => '6 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        // Attempt without required fields
        $resFail = $this->postJson('/api/enquiries', [
            'course_id' => $course->id,
        ]);
        $resFail->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'phone']);

        // Successful public enquiry
        $resSuccess = $this->postJson('/api/enquiries', [
            'name' => 'John Doe',
            'email' => 'john.public@example.com',
            'phone' => '+1 555-0199',
            'course_id' => $course->id,
            'preferred_time' => 'Morning (9 AM - 12 PM)',
            'message' => 'Interested in AWS certifications.',
            'user_id' => 9999, // Spoofed user_id must be ignored
        ]);

        $resSuccess->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'John Doe',
                'email' => 'john.public@example.com',
                'phone' => '+1 555-0199',
                'user_id' => null,
            ]);

        $this->assertDatabaseHas('enquiries', [
            'email' => 'john.public@example.com',
            'user_id' => null,
            'status' => 'new',
        ]);
    }

    public function test_authenticated_student_enquiry_uses_server_side_user_details_and_associates_user_id(): void
    {
        $student = User::factory()->create([
            'name' => 'Aditi Sharma',
            'email' => 'aditi.student@example.com',
            'phone' => '+91 9876543210',
            'role' => 'student',
        ]);

        Sanctum::actingAs($student);

        $course = Course::create([
            'title' => 'Python with AI & Machine Learning',
            'slug' => 'python-ai-ml',
            'description' => 'AI foundations',
            'instructor' => 'Aman Verma',
            'duration' => '10 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        // Student submits enquiry with only slot and message, client name/email/phone/user_id omitted or spoofed
        $res = $this->postJson('/api/enquiries', [
            'course_id' => $course->id,
            'preferred_time' => 'Evening (4 PM - 8 PM)',
            'message' => 'Want to explore computer vision modules.',
            'user_id' => 8888, // Spoofed user_id must be ignored
            'name' => 'Hacker Name', // Spoofed name must be ignored
            'email' => 'hacker@example.com', // Spoofed email must be ignored
        ]);

        $res->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Aditi Sharma',
                'email' => 'aditi.student@example.com',
                'phone' => '+91 9876543210',
                'user_id' => $student->id,
            ]);

        $this->assertDatabaseHas('enquiries', [
            'user_id' => $student->id,
            'name' => 'Aditi Sharma',
            'email' => 'aditi.student@example.com',
            'course_id' => $course->id,
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('enquiry_notes', [
            'user_id' => $student->id,
            'user_name' => 'System',
        ]);
    }

    public function test_duplicate_active_enquiry_protection_returns_existing_enquiry_without_creating_new_lead(): void
    {
        $student = User::factory()->create([
            'name' => 'Rohan Gupta',
            'email' => 'rohan@example.com',
            'phone' => '9988776655',
            'role' => 'student',
        ]);

        Sanctum::actingAs($student);

        $course = Course::create([
            'title' => 'Full Stack Web Development',
            'slug' => 'full-stack-web',
            'description' => 'Full stack web development',
            'instructor' => 'Sarah Johnson',
            'duration' => '12 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        // First submission creates enquiry
        $res1 = $this->postJson('/api/enquiries', [
            'course_id' => $course->id,
            'preferred_time' => 'Morning (9 AM - 12 PM)',
            'message' => 'First enquiry',
        ]);
        $res1->assertStatus(201);
        $firstEnquiryId = $res1->json('enquiry.id');

        $this->assertEquals(1, Enquiry::where('user_id', $student->id)->where('course_id', $course->id)->count());

        // Second submission detects existing active enquiry
        $res2 = $this->postJson('/api/enquiries', [
            'course_id' => $course->id,
            'preferred_time' => 'Evening (4 PM - 8 PM)',
            'message' => 'Duplicate attempt',
        ]);

        $res2->assertStatus(200)
            ->assertJsonFragment([
                'already_exists' => true,
            ]);
        $this->assertEquals($firstEnquiryId, $res2->json('enquiry.id'));

        // Database count must still be 1 (no duplicate created)
        $this->assertEquals(1, Enquiry::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_new_enquiry_allowed_if_previous_enquiry_was_closed(): void
    {
        $student = User::factory()->create([
            'name' => 'Kavita Roy',
            'email' => 'kavita@example.com',
            'phone' => '9876543210',
            'role' => 'student',
        ]);

        Sanctum::actingAs($student);

        $course = Course::create([
            'title' => 'SAP FICO Financial Accounting',
            'slug' => 'sap-fico-demo',
            'description' => 'SAP FICO',
            'instructor' => 'Neha Patel',
            'duration' => '8 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        // Previous closed enquiry
        Enquiry::create([
            'user_id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone,
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => Enquiry::STATUS_CLOSED,
        ]);

        // New active enquiry should be allowed
        $res = $this->postJson('/api/enquiries', [
            'course_id' => $course->id,
            'preferred_time' => 'Weekend Anytime',
            'message' => 'Re-applying for the upcoming cohort.',
        ]);

        $res->assertStatus(201);
        $this->assertEquals(2, Enquiry::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_check_course_enquiry_endpoint_returns_active_enquiry_state(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $course = Course::create([
            'title' => 'Data Science & Analytics',
            'slug' => 'data-science',
            'description' => 'Data Science track',
            'instructor' => 'Dr. Meera',
            'duration' => '10 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        // Initially no enquiry
        $resCheck1 = $this->getJson("/api/courses/{$course->id}/enquiry");
        $resCheck1->assertStatus(200)
            ->assertJson(['has_enquiry' => false, 'enquiry' => null]);

        // Submit enquiry
        Enquiry::create([
            'user_id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone ?? '9999999999',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => Enquiry::STATUS_DEMO_SCHEDULED,
            'demo_date' => '2026-09-01',
            'demo_time' => '4:00 PM',
        ]);

        // Now returns active enquiry
        $resCheck2 = $this->getJson("/api/courses/{$course->id}/enquiry");
        $resCheck2->assertStatus(200)
            ->assertJsonFragment([
                'has_enquiry' => true,
                'status' => 'demo_scheduled',
            ]);
    }

    public function test_admin_can_distinguish_student_enquiries_from_public_enquiries(): void
    {
        $student = User::factory()->create([
            'name' => 'Registered Learner',
            'email' => 'learner@example.com',
            'role' => 'student',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        // 1. Authenticated Student Enquiry
        $studentEnquiry = Enquiry::create([
            'user_id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => '1234567890',
            'course_title' => 'AI Course',
            'status' => 'new',
        ]);

        // 2. Public Visitor Enquiry
        $publicEnquiry = Enquiry::create([
            'user_id' => null,
            'name' => 'Anonymous Visitor',
            'email' => 'visitor@example.com',
            'phone' => '0987654321',
            'course_title' => 'Web Dev Course',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);

        $resList = $this->getJson('/api/admin/enquiries');
        $resList->assertStatus(200);

        $list = collect($resList->json('data'));
        $lead1 = $list->firstWhere('id', $studentEnquiry->id);
        $lead2 = $list->firstWhere('id', $publicEnquiry->id);

        $this->assertNotNull($lead1['user_id']);
        $this->assertEquals($student->id, $lead1['user_id']);
        $this->assertNotNull($lead1['user']);
        $this->assertEquals('student', $lead1['user']['role']);

        $this->assertNull($lead2['user_id']);
        $this->assertNull($lead2['user']);
    }

    public function test_course_listing_includes_is_enrolled_for_authenticated_student(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course1 = Course::create([
            'title' => 'Enrolled Course',
            'slug' => 'enrolled-course',
            'description' => 'Desc 1',
            'instructor' => 'Inst 1',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        $course2 = Course::create([
            'title' => 'Un-enrolled Course',
            'slug' => 'unenrolled-course',
            'description' => 'Desc 2',
            'instructor' => 'Inst 2',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course1->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $res = $this->getJson('/api/courses');
        $res->assertStatus(200);

        $courses = collect($res->json());
        $c1 = $courses->firstWhere('id', $course1->id);
        $c2 = $courses->firstWhere('id', $course2->id);

        $this->assertTrue($c1['is_enrolled']);
        $this->assertFalse($c2['is_enrolled'] ?? false);
    }
}
