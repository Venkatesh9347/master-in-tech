<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionsPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_enquiry_creation_generates_lead_and_system_note(): void
    {
        $course = Course::create([
            'title' => 'SAP FICO Financial Accounting',
            'slug' => 'sap-fico-accounting',
            'description' => 'Enterprise financial management in SAP',
            'instructor' => 'Senior Faculty',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        $res = $this->postJson('/api/enquiries', [
            'name' => 'Steve Rogers',
            'email' => 'steve099@gmail.com',
            'phone' => '9899320575',
            'course_id' => $course->id,
            'preferred_time' => 'Afternoon (12 PM - 4 PM)',
            'message' => 'Interested in SAP module and career placement.',
        ]);

        $res->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Steve Rogers',
                'email' => 'steve099@gmail.com',
                'status' => 'new',
            ]);

        $this->assertDatabaseHas('enquiries', [
            'email' => 'steve099@gmail.com',
            'course_title' => 'SAP FICO Financial Accounting',
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('enquiry_notes', [
            'user_name' => 'System',
        ]);
    }

    public function test_admin_can_view_leads_with_pipeline_stats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        Enquiry::create([
            'name' => 'Lead One',
            'email' => 'lead1@example.com',
            'phone' => '1111111111',
            'status' => 'new',
        ]);

        Enquiry::create([
            'name' => 'Lead Two',
            'email' => 'lead2@example.com',
            'phone' => '2222222222',
            'status' => 'demo_scheduled',
        ]);

        // Stats test
        $resStats = $this->getJson('/api/admin/enquiries/stats');
        $resStats->assertStatus(200)
            ->assertJsonFragment(['total' => 2, 'new' => 1, 'demo_scheduled' => 1]);

        // List test (paginated envelope with metadata)
        $resList = $this->getJson('/api/admin/enquiries?status=new');
        $resList->assertStatus(200);
        $this->assertCount(1, $resList->json('data'));
        $this->assertEquals(1, $resList->json('total'));
        $this->assertEquals(1, $resList->json('current_page'));
    }

    public function test_admin_can_update_lead_status_and_schedule_demo(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admissions Lead']);
        Sanctum::actingAs($admin);

        $enquiry = Enquiry::create([
            'name' => 'Maya Sen',
            'email' => 'maya@example.com',
            'phone' => '9988776655',
            'status' => 'new',
        ]);

        $resUpdate = $this->putJson("/api/admin/enquiries/{$enquiry->id}", [
            'status' => 'demo_scheduled',
            'demo_date' => '2026-08-25',
            'demo_time' => '3:00 PM IST',
            'assigned_agent' => 'Admissions Lead',
            'note' => 'Called student and scheduled 1-on-1 demo with faculty.',
        ]);

        $resUpdate->assertStatus(200)
            ->assertJsonFragment(['status' => 'demo_scheduled', 'assigned_agent' => 'Admissions Lead']);

        $this->assertEquals('demo_scheduled', $enquiry->fresh()->status);
        $this->assertDatabaseHas('enquiry_notes', [
            'enquiry_id' => $enquiry->id,
            'note' => 'Called student and scheduled 1-on-1 demo with faculty.',
        ]);
    }

    public function test_admin_can_add_follow_up_notes_to_lead(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Sarah Admin']);
        Sanctum::actingAs($admin);

        $enquiry = Enquiry::create([
            'name' => 'Arjun Patel',
            'email' => 'arjun@example.com',
            'phone' => '9876543210',
            'status' => 'contacted',
        ]);

        $res = $this->postJson("/api/admin/enquiries/{$enquiry->id}/notes", [
            'note' => 'Student requested syllabus PDF over WhatsApp.',
        ]);

        $res->assertStatus(201)
            ->assertJsonFragment(['note' => 'Student requested syllabus PDF over WhatsApp.', 'user_name' => 'Sarah Admin']);

        $this->assertDatabaseHas('enquiry_notes', [
            'enquiry_id' => $enquiry->id,
            'user_name' => 'Sarah Admin',
        ]);
    }

    public function test_admin_can_confirm_admission_without_immediate_enrollment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $enquiry = Enquiry::create([
            'name' => 'Priya Nair',
            'email' => 'priya@example.com',
            'phone' => '9123456780',
            'status' => 'interested',
        ]);

        // Confirm admission stage
        $res = $this->putJson("/api/admin/enquiries/{$enquiry->id}", [
            'status' => 'admission_confirmed',
            'note' => 'Candidate passed screening and confirmed batch enrollment for next Monday.',
        ]);

        $res->assertStatus(200)
            ->assertJsonFragment(['status' => 'admission_confirmed']);

        $this->assertEquals('admission_confirmed', $enquiry->fresh()->status);
        // Student should NOT have active course enrollment yet
        $this->assertNull($enquiry->fresh()->enrolled_user_id);
    }

    public function test_admin_can_enroll_student_granting_lms_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Chief Admin']);
        Sanctum::actingAs($admin);

        $course = Course::create([
            'title' => 'Full Stack Web Development Track',
            'slug' => 'full-stack-track',
            'description' => 'Full stack track',
            'instructor' => 'Faculty Team',
            'duration' => '12 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        $enquiry = Enquiry::create([
            'name' => 'Steve Rogers',
            'email' => 'steve099@gmail.com',
            'phone' => '9899320575',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'admission_confirmed',
        ]);

        // Enroll Student action (B3 pay-before-classroom: no verified payment
        // yet, so the admission stays pending and LMS remains blocked).
        $resEnroll = $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
            'password' => 'steveSecret123',
        ]);

        $resEnroll->assertStatus(200)
            ->assertJsonFragment(['status' => 'payment_pending'])
            ->assertJsonFragment(['payment_required' => true]);

        // Verify User was provisioned with student role
        $student = User::where('email', 'steve099@gmail.com')->first();
        $this->assertNotNull($student);
        $this->assertEquals('student', $student->role);

        // Verify Course Enrollment was created pending (no LMS access).
        $enrollment = CourseEnrollment::where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->first();
        $this->assertNotNull($enrollment);
        $this->assertEquals('pending', $enrollment->status);

        // Verify Student cannot yet access classroom (pending blocks LMS).
        Sanctum::actingAs($student);
        $resBlocked = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $resBlocked->assertStatus(403);

        // Emergency admin override activates LMS access with a full audit trail.
        Sanctum::actingAs($admin);
        $resOverride = $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
            'override_reason' => 'Scholarship admission approved by registrar office.',
        ]);
        $resOverride->assertStatus(200);
        $this->assertEquals('active', CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->first()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'enrollment_payment_override',
        ]);

        // Verify Student can now access classroom
        Sanctum::actingAs($student);
        $resStudentShow = $this->getJson("/api/courses/{$course->id}");
        $resStudentShow->assertStatus(200)
            ->assertJsonFragment(['is_enrolled' => true]);
    }

    public function test_student_and_tutor_cannot_access_or_modify_leads(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        $enquiry = Enquiry::create([
            'name' => 'Confidential Lead',
            'email' => 'confidential@example.com',
            'phone' => '9999999999',
            'status' => 'new',
        ]);

        // Student unauthorized checks
        Sanctum::actingAs($student);
        $this->getJson('/api/admin/enquiries')->assertStatus(403);
        $this->getJson("/api/admin/enquiries/{$enquiry->id}")->assertStatus(403);
        $this->putJson("/api/admin/enquiries/{$enquiry->id}", ['status' => 'contacted'])->assertStatus(403);
        $this->postJson("/api/admin/enquiries/{$enquiry->id}/notes", ['note' => 'Hacker'])->assertStatus(403);
        $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [])->assertStatus(403);

        // Tutor unauthorized checks
        Sanctum::actingAs($tutor);
        $this->getJson('/api/admin/enquiries')->assertStatus(403);
        $this->getJson("/api/admin/enquiries/{$enquiry->id}")->assertStatus(403);
        $this->putJson("/api/admin/enquiries/{$enquiry->id}", ['status' => 'contacted'])->assertStatus(403);
        $this->postJson("/api/admin/enquiries/{$enquiry->id}/notes", ['note' => 'Tutor note'])->assertStatus(403);
        $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [])->assertStatus(403);
    }

    public function test_complete_admissions_pipeline_lifecycle_e2e(): void
    {
        // 1. PUBLIC COURSE & ENQUIRY
        $course = Course::create([
            'title' => 'SAP FICO Financial Accounting',
            'slug' => 'sap-fico-accounting-e2e',
            'description' => 'SAP financial modules and accounting capstone',
            'instructor' => 'Senior SAP Mentor',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'price' => 19999, // Internal admin pricing
            'is_published' => true,
        ]);

        // Public course show hides price
        $resPublicShow = $this->getJson("/api/courses/{$course->id}");
        $resPublicShow->assertStatus(200);
        $this->assertArrayNotHasKey('price', $resPublicShow->json());

        // Public user submits Free Live Demo request
        $resEnquiry = $this->postJson('/api/enquiries', [
            'name' => 'Steve',
            'email' => 'steve099@gmail.com',
            'phone' => '9899320575',
            'course_id' => $course->id,
            'preferred_time' => 'Afternoon (12 PM - 4 PM)',
            'message' => 'Want a live demo of SAP FICO ledger configuration.',
        ]);
        $resEnquiry->assertStatus(201);
        $enquiryId = $resEnquiry->json('enquiry.id');

        // 2. ADMIN RECOGNITION & LISTING
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admissions Desk']);
        Sanctum::actingAs($admin);

        $resAdminList = $this->getJson('/api/admin/enquiries');
        $resAdminList->assertStatus(200);
        $lead = collect($resAdminList->json('data'))->firstWhere('id', $enquiryId);
        $this->assertNotNull($lead);
        $this->assertEquals('Steve', $lead['name']);
        $this->assertEquals('steve099@gmail.com', $lead['email']);
        $this->assertEquals('9899320575', $lead['phone']);
        $this->assertEquals('SAP FICO Financial Accounting', $lead['course_title']);
        $this->assertEquals('Afternoon (12 PM - 4 PM)', $lead['preferred_time']);
        $this->assertEquals('new', $lead['status']);

        // 3. PIPELINE TRANSITIONS & PERSISTENCE
        // Stage: CONTACTED
        $resContacted = $this->putJson("/api/admin/enquiries/{$enquiryId}", [
            'status' => 'contacted',
            'assigned_agent' => 'Admissions Desk',
            'note' => 'Called student Steve — confirmed interest in SAP FICO.',
        ]);
        $resContacted->assertStatus(200);
        $this->assertEquals('contacted', Enquiry::find($enquiryId)->status);

        // Stage: DEMO_SCHEDULED
        $resDemoSched = $this->putJson("/api/admin/enquiries/{$enquiryId}", [
            'status' => 'demo_scheduled',
            'demo_date' => '2026-08-25',
            'demo_time' => '2:00 PM',
            'note' => 'Demo scheduled for 25 Aug at 2:00 PM.',
        ]);
        $resDemoSched->assertStatus(200);
        $this->assertEquals('demo_scheduled', Enquiry::find($enquiryId)->status);
        $this->assertNotNull(Enquiry::find($enquiryId)->demo_date);

        // Stage: DEMO_COMPLETED
        $resDemoComp = $this->putJson("/api/admin/enquiries/{$enquiryId}", [
            'status' => 'demo_completed',
            'demo_outcome' => 'Attended live demo. Highly interested in certification.',
        ]);
        $resDemoComp->assertStatus(200);
        $this->assertEquals('demo_completed', Enquiry::find($enquiryId)->status);

        // Stage: INTERESTED
        $resInterested = $this->putJson("/api/admin/enquiries/{$enquiryId}", [
            'status' => 'interested',
            'note' => 'Candidate requested weekend morning batch schedule.',
        ]);
        $resInterested->assertStatus(200);
        $this->assertEquals('interested', Enquiry::find($enquiryId)->status);

        // Stage: ADMISSION_CONFIRMED
        $resAdmConf = $this->putJson("/api/admin/enquiries/{$enquiryId}", [
            'status' => 'admission_confirmed',
            'note' => 'Admission confirmed for Upcoming Cohort.',
        ]);
        $resAdmConf->assertStatus(200);
        $this->assertEquals('admission_confirmed', Enquiry::find($enquiryId)->status);

        // Verify NOT enrolled yet
        $this->assertNull(Enquiry::find($enquiryId)->enrolled_user_id);

        // 4. ADMIN ENROLLMENT ACTION (B3: pending without verified payment).
        $resEnroll = $this->postJson("/api/admin/enquiries/{$enquiryId}/enroll", [
            'course_id' => $course->id,
            'password' => 'steveSecure123',
        ]);
        $resEnroll->assertStatus(200)
            ->assertJsonFragment(['payment_required' => true]);
        $this->assertEquals('payment_pending', Enquiry::find($enquiryId)->status);

        // Verify Student user provisioned
        $student = User::where('email', 'steve099@gmail.com')->first();
        $this->assertNotNull($student);
        $this->assertEquals('student', $student->role);
        $this->assertEquals('pending', CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->first()->status);

        // Pending blocks LMS access.
        Sanctum::actingAs($student);
        $this->getJson("/api/courses/{$course->id}/lms-progress")->assertStatus(403);
        Sanctum::actingAs($admin);

        // Admin override activates the admission (audited).
        $resOverride = $this->postJson("/api/admin/enquiries/{$enquiryId}/enroll", [
            'course_id' => $course->id,
            'override_reason' => 'Registrar-approved emergency admission for e2e cohort.',
        ]);
        $resOverride->assertStatus(200);
        $this->assertEquals('enrolled', Enquiry::find($enquiryId)->status);

        // 5. DUPLICATE ADMISSION ATTEMPT SAFETY
        Sanctum::actingAs($admin);
        $resDuplicateEnroll = $this->postJson("/api/admin/enquiries/{$enquiryId}/enroll", [
            'course_id' => $course->id,
        ]);
        $resDuplicateEnroll->assertStatus(200);
        $this->assertEquals(1, CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->count());

        // 6. STUDENT AUTHENTICATION & LMS CLASSROOM ACCESS
        Sanctum::actingAs($student);
        $resStudentCourse = $this->getJson("/api/courses/{$course->id}");
        $resStudentCourse->assertStatus(200)
            ->assertJsonFragment(['is_enrolled' => true]);

        // Student accesses enrolled courses list
        $resMyLearning = $this->getJson('/api/student/enrolled-courses');
        if ($resMyLearning->status() === 200) {
            $this->assertNotEmpty($resMyLearning->json());
        }

        // 7. SECURITY ACCESS CONTROL
        // Student cannot access admin enquiries
        $this->getJson('/api/admin/enquiries')->assertStatus(403);
        $this->postJson("/api/admin/enquiries/{$enquiryId}/enroll", [])->assertStatus(403);

        // Tutor cannot access admin enquiries or admit students
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);
        $this->getJson('/api/admin/enquiries')->assertStatus(403);
        $this->postJson("/api/admin/enquiries/{$enquiryId}/enroll", [])->assertStatus(403);
    }
}
