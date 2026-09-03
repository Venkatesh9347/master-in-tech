<?php

namespace Tests\Feature;

use App\Mail\StudentLoginOtpMail;
use App\Models\Course;
use App\Models\Enquiry;
use App\Models\StudentLoginOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Goal3AAdminStudentAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_public_registration_is_strictly_blocked(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Stranger Visitor',
            'email' => 'stranger@example.com',
            'password' => 'secret12345',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Public registration is disabled. Student accounts are created by MasterInTech administration following counselling. Please submit an enquiry to get started.',
            ]);

        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_unregistered_google_and_mobile_logins_are_rejected(): void
    {
        // 1. Unregistered Google Sign-In
        $googleRes = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:unregistered.lead@gmail.com:Unregistered Lead:sub_unreg_1',
        ]);

        $googleRes->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);
        $this->assertDatabaseMissing('users', ['email' => 'unregistered.lead@gmail.com']);

        // 2. Unregistered Mobile Sign-In
        $mobileRes = $this->postJson('/api/auth/mobile/send-otp', [
            'phone' => '+91 99999 88888',
        ]);

        $mobileRes->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_admin_can_provision_student_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $course = Course::create([
            'title' => 'Artificial Intelligence & Deep Learning',
            'slug' => 'artificial-intelligence-deep-learning',
            'description' => 'Comprehensive AI and Deep Learning training program.',
            'category' => 'AI & ML',
            'difficulty' => 'Intermediate',
            'duration' => '12 Weeks',
            'instructor' => 'Faculty Team',
            'is_published' => true,
            'status' => 'published',
            'price' => 0,
            'average_rating' => 4.9,
        ]);

        $createRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', [
            'name' => 'Aditi Sharma',
            'email' => 'aditi.sharma@gmail.com',
            'phone' => '+91 98765 43210',
            'student_id' => 'STU-1001',
            'role' => 'student',
            'status' => 'active',
            'course_id' => $course->id,
        ]);

        $createRes->assertStatus(201)
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email', 'student_id', 'status']])
            ->assertJson([
                'user' => [
                    'name' => 'Aditi Sharma',
                    'email' => 'aditi.sharma@gmail.com',
                    'student_id' => 'STU-1001',
                    'status' => 'active',
                    'role' => 'student',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'aditi.sharma@gmail.com',
            'student_id' => 'STU-1001',
            'phone' => '+91 98765 43210',
            'status' => 'active',
        ]);

        // Auto enrolled in course
        $student = User::where('email', 'aditi.sharma@gmail.com')->first();
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_dual_authentication_resolves_to_same_student_identity_without_duplication(): void
    {
        // 1. Seed approved student
        $student = User::create([
            'name' => 'Vikram Patel',
            'email' => 'vikram.patel@gmail.com',
            'phone' => '+919876500001',
            'student_id' => 'STU-1002',
            'role' => 'student',
            'status' => 'active',
        ]);

        // 2. Student logs in with Google
        $googleLoginRes = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:vikram.patel@gmail.com:Vikram Patel:google_sub_vikram',
        ]);

        $googleLoginRes->assertStatus(200)
            ->assertJson(['requires_otp' => true, 'expires_in' => 30]);

        $googleTempToken = $googleLoginRes->json('temp_token');

        $googleSentOtp = null;
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) use (&$googleSentOtp) {
            $googleSentOtp = $mail->otp;
            return true;
        });

        // Verify Google OTP
        $googleVerifyRes = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $googleTempToken,
            'otp' => $googleSentOtp,
        ]);

        $googleVerifyRes->assertStatus(200);
        $this->assertEquals($student->id, $googleVerifyRes->json('user.id'));
        $this->assertEquals('STU-1002', $googleVerifyRes->json('user.student_id'));

        // 3. Student logs in with Mobile Number
        $mobileLoginRes = $this->postJson('/api/auth/mobile/send-otp', [
            'phone' => '+91 98765 00001',
        ]);

        $mobileLoginRes->assertStatus(200)
            ->assertJson(['requires_otp' => true, 'expires_in' => 30]);

        $mobileTempToken = $mobileLoginRes->json('temp_token');

        $mobileSentOtp = null;
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) use (&$mobileSentOtp) {
            $mobileSentOtp = $mail->otp;
            return true;
        });

        // Verify Mobile OTP
        $mobileVerifyRes = $this->postJson('/api/auth/mobile/verify-otp', [
            'temp_token' => $mobileTempToken,
            'otp' => $mobileSentOtp,
        ]);

        $mobileVerifyRes->assertStatus(200);
        $this->assertEquals($student->id, $mobileVerifyRes->json('user.id'));
        $this->assertEquals('STU-1002', $mobileVerifyRes->json('user.student_id'));

        // Ensure ONLY ONE record exists for this student
        $this->assertEquals(1, User::where('email', 'vikram.patel@gmail.com')->count());
        $this->assertEquals(1, User::where('student_id', 'STU-1002')->count());
    }

    public function test_admin_can_convert_enquiry_to_student(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $course = Course::create([
            'title' => 'SAP Enterprise Architecture',
            'slug' => 'sap-enterprise-architecture',
            'description' => 'Comprehensive SAP Enterprise Architecture training program.',
            'category' => 'SAP',
            'difficulty' => 'Advanced',
            'duration' => '8 Weeks',
            'instructor' => 'SAP Specialist',
            'is_published' => true,
            'status' => 'published',
            'price' => 0,
            'average_rating' => 4.8,
        ]);

        $enquiry = Enquiry::create([
            'name' => 'Kavita Reddy',
            'email' => 'kavita.reddy@gmail.com',
            'phone' => '+91 91234 56789',
            'course_id' => $course->id,
            'course_title' => 'SAP Enterprise Architecture',
            'status' => 'admission_confirmed',
        ]);

        // Convert enquiry to enrolled student
        $enrollRes = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
            'name' => 'Kavita Reddy',
            'email' => 'kavita.reddy@gmail.com',
        ]);

        $enrollRes->assertStatus(200)
            ->assertJsonStructure(['message', 'user', 'enquiry']);

        $enquiry->refresh();
        $this->assertEquals('enrolled', $enquiry->status);
        $this->assertNotNull($enquiry->enrolled_user_id);

        $createdUser = User::find($enquiry->enrolled_user_id);
        $this->assertNotNull($createdUser);
        $this->assertEquals('kavita.reddy@gmail.com', $createdUser->email);
        $this->assertNotNull($createdUser->student_id);
    }
}
