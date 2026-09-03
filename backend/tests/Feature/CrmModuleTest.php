<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CrmActivity;
use App\Models\CrmFollowUp;
use App\Models\Enquiry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmModuleTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);
        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => $attributes['code'] ?? 'AI',
            'description' => 'Comprehensive technical training course description.',
            'category' => 'Artificial Intelligence',
            'instructor' => 'Senior Specialist',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ], $attributes));
    }

    public function test_crm_authorization_permissions(): void
    {
        // 1. Unauthenticated Guest -> 401
        $this->getJson('/api/admin/crm/stats')->assertStatus(401);
        $this->getJson('/api/admin/crm/leads')->assertStatus(401);
        $this->postJson('/api/admin/crm/leads', [])->assertStatus(401);

        // 2. Student Role -> 403 Forbidden
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);
        $this->getJson('/api/admin/crm/stats')->assertStatus(403);
        $this->getJson('/api/admin/crm/leads')->assertStatus(403);

        // 3. Tutor Role -> 403 Forbidden
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);
        $this->getJson('/api/admin/crm/stats')->assertStatus(403);
        $this->getJson('/api/admin/crm/leads')->assertStatus(403);

        // 4. Counsellor Role -> 200 OK (Allowed)
        $counsellor = User::factory()->create(['role' => 'counsellor']);
        Sanctum::actingAs($counsellor);
        $this->getJson('/api/admin/crm/stats')->assertStatus(200);
        $this->getJson('/api/admin/crm/leads')->assertStatus(200);

        // 5. Admin Role -> 200 OK (Allowed)
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/crm/stats')->assertStatus(200);
        $this->getJson('/api/admin/crm/leads')->assertStatus(200);

        // 6. Super Admin Role -> 200 OK (Allowed)
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/admin/crm/stats')->assertStatus(200);
        $this->getJson('/api/admin/crm/leads')->assertStatus(200);
    }

    public function test_crm_dashboard_kpi_metrics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $counsellor = User::factory()->create(['role' => 'counsellor', 'name' => 'Sarah Counsellor']);
        $course = $this->createCourse(['title' => 'Generative AI']);

        // Create sample leads across stages
        $leadNew = Enquiry::create([
            'name' => 'John New',
            'email' => 'john@test.com',
            'phone' => '1234567890',
            'status' => Enquiry::STATUS_NEW,
            'source' => 'website',
            'priority' => 'hot',
            'course_id' => $course->id,
            'assigned_counsellor_id' => $counsellor->id,
        ]);

        $leadContacted = Enquiry::create([
            'name' => 'Jane Contacted',
            'email' => 'jane@test.com',
            'phone' => '1234567891',
            'status' => Enquiry::STATUS_CONTACTED,
            'source' => 'google_ads',
            'priority' => 'warm',
            'course_id' => $course->id,
        ]);

        $leadInterested = Enquiry::create([
            'name' => 'Bob Interested',
            'email' => 'bob@test.com',
            'phone' => '1234567892',
            'status' => Enquiry::STATUS_INTERESTED,
            'source' => 'referral',
            'priority' => 'hot',
            'course_id' => $course->id,
        ]);

        $leadDemo = Enquiry::create([
            'name' => 'Alice Demo',
            'email' => 'alice@test.com',
            'phone' => '1234567893',
            'status' => Enquiry::STATUS_DEMO_SCHEDULED,
            'source' => 'website',
            'priority' => 'hot',
            'course_id' => $course->id,
        ]);

        $leadConverted = Enquiry::create([
            'name' => 'Charlie Converted',
            'email' => 'charlie@test.com',
            'phone' => '1234567894',
            'status' => Enquiry::STATUS_CONVERTED,
            'source' => 'direct_call',
            'priority' => 'hot',
            'course_id' => $course->id,
        ]);

        $leadLost = Enquiry::create([
            'name' => 'David Lost',
            'email' => 'david@test.com',
            'phone' => '1234567895',
            'status' => Enquiry::STATUS_LOST,
            'source' => 'social_media',
            'priority' => 'cold',
            'course_id' => $course->id,
        ]);

        // Create follow-ups: 1 overdue, 1 today, 1 upcoming
        CrmFollowUp::create([
            'enquiry_id' => $leadNew->id,
            'assigned_to' => $counsellor->id,
            'scheduled_at' => now()->subDays(2),
            'status' => CrmFollowUp::STATUS_PENDING,
            'title' => 'Overdue call',
        ]);

        CrmFollowUp::create([
            'enquiry_id' => $leadContacted->id,
            'assigned_to' => $counsellor->id,
            'scheduled_at' => now()->setHour(14),
            'status' => CrmFollowUp::STATUS_PENDING,
            'title' => 'Today call',
        ]);

        CrmFollowUp::create([
            'enquiry_id' => $leadInterested->id,
            'assigned_to' => $counsellor->id,
            'scheduled_at' => now()->addDays(3),
            'status' => CrmFollowUp::STATUS_PENDING,
            'title' => 'Upcoming call',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/admin/crm/stats');
        $res->assertStatus(200);

        $data = $res->json();
        $this->assertEquals(6, $data['total_leads']);
        $this->assertEquals(1, $data['new_leads']);
        $this->assertEquals(1, $data['contacted']);
        $this->assertEquals(1, $data['interested']);
        $this->assertEquals(1, $data['demos']);
        $this->assertEquals(1, $data['converted']);
        $this->assertEquals(1, $data['lost']);
        $this->assertEquals(1, $data['overdue_follow_ups']);
        $this->assertEquals(1, $data['todays_follow_ups']);
        $this->assertEquals(1, $data['upcoming_follow_ups']);
        $this->assertEquals(2, $data['follow_ups_due']); // overdue (1) + today (1)
        $this->assertEquals(16.7, $data['conversion_rate']); // 1 / 6 = 16.7%
    }

    public function test_lead_creation_and_duplicate_prevention(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $counsellor = User::factory()->create(['role' => 'counsellor', 'name' => 'Counsellor Mike']);
        $course = $this->createCourse(['title' => 'Full Stack Cloud Development', 'code' => 'FSCD']);

        Sanctum::actingAs($admin);

        // 1. Create lead
        $res = $this->postJson('/api/admin/crm/leads', [
            'name' => 'Rajesh Sharma',
            'email' => 'rajesh@example.com',
            'phone' => '+91 9876543210',
            'course_id' => $course->id,
            'source' => 'google_ads',
            'priority' => 'hot',
            'assigned_counsellor_id' => $counsellor->id,
            'qualification' => 'B.Tech CSE',
            'experience_level' => 'fresher',
            'city' => 'Hyderabad',
            'expected_revenue' => 45000,
            'next_follow_up_date' => now()->addDays(2)->toDateString(),
            'next_follow_up_time' => '11:00 AM',
            'message' => 'Interested in Full Stack Cloud with placement support.',
        ]);

        $res->assertStatus(201);
        $leadId = $res->json('lead.id');

        $this->assertDatabaseHas('enquiries', [
            'id' => $leadId,
            'name' => 'Rajesh Sharma',
            'email' => 'rajesh@example.com',
            'source' => 'google_ads',
            'priority' => 'hot',
            'assigned_counsellor_id' => $counsellor->id,
            'city' => 'Hyderabad',
        ]);

        // Verify initial timeline activity created
        $this->assertDatabaseHas('crm_activities', [
            'enquiry_id' => $leadId,
            'activity_type' => 'note',
            'title' => 'Lead created in CRM',
        ]);

        // Verify scheduled follow-up created
        $this->assertDatabaseHas('crm_follow_ups', [
            'enquiry_id' => $leadId,
            'assigned_to' => $counsellor->id,
            'status' => 'pending',
        ]);

        // 2. Duplicate active lead prevention for same email and course
        $resDuplicate = $this->postJson('/api/admin/crm/leads', [
            'name' => 'Rajesh Sharma Duplicate',
            'email' => 'rajesh@example.com',
            'phone' => '+91 9876543210',
            'course_id' => $course->id,
        ]);

        $resDuplicate->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'An active lead already exists for this candidate.',
            ]);
    }

    public function test_lead_editing_and_automatic_timeline_tracking(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $counsellor1 = User::factory()->create(['role' => 'counsellor', 'name' => 'Agent Alpha']);
        $counsellor2 = User::factory()->create(['role' => 'counsellor', 'name' => 'Agent Beta']);
        $course = $this->createCourse(['title' => 'Data Science AI']);

        $lead = Enquiry::create([
            'name' => 'Emily Watson',
            'email' => 'emily@example.com',
            'phone' => '1122334455',
            'status' => Enquiry::STATUS_NEW,
            'priority' => 'medium',
            'source' => 'website',
            'course_id' => $course->id,
            'assigned_counsellor_id' => $counsellor1->id,
        ]);

        Sanctum::actingAs($admin);

        // Update lead status to interested and reassign counsellor
        $res = $this->putJson("/api/admin/crm/leads/{$lead->id}", [
            'status' => Enquiry::STATUS_INTERESTED,
            'priority' => 'hot',
            'assigned_counsellor_id' => $counsellor2->id,
            'city' => 'Bangalore',
        ]);

        $res->assertStatus(200);

        $this->assertEquals(Enquiry::STATUS_INTERESTED, $lead->fresh()->status);
        $this->assertEquals('hot', $lead->fresh()->priority);
        $this->assertEquals($counsellor2->id, $lead->fresh()->assigned_counsellor_id);

        // Verify status change logged in timeline
        $this->assertDatabaseHas('crm_activities', [
            'enquiry_id' => $lead->id,
            'activity_type' => 'status_change',
            'title' => 'Status changed to INTERESTED',
        ]);

        // Verify counsellor reassignment logged in timeline
        $this->assertDatabaseHas('crm_activities', [
            'enquiry_id' => $lead->id,
            'activity_type' => 'note',
            'title' => 'Assigned to Agent Beta',
        ]);
    }

    public function test_lead_timeline_logging_calls_and_payments(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);
        $lead = Enquiry::create([
            'name' => 'Arun Kumar',
            'email' => 'arun@example.com',
            'phone' => '9988776655',
            'status' => Enquiry::STATUS_CONTACTED,
            'expected_revenue' => 50000,
            'amount_paid' => 0,
        ]);

        Sanctum::actingAs($counsellor);

        // 1. Log outbound call activity
        $resCall = $this->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
            'activity_type' => 'call',
            'title' => 'Discussion on curriculum syllabus',
            'description' => 'Candidate asked questions regarding weekend batches and placement assistance.',
            'metadata' => [
                'call_duration' => '12 mins',
                'call_outcome' => 'interested',
            ],
        ]);

        $resCall->assertStatus(201);
        $this->assertDatabaseHas('crm_activities', [
            'enquiry_id' => $lead->id,
            'activity_type' => 'call',
            'title' => 'Discussion on curriculum syllabus',
        ]);

        // 2. Log partial payment event
        $resPayment = $this->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
            'activity_type' => 'payment_event',
            'title' => 'Received registration fee token',
            'description' => 'Initial token payment received via UPI.',
            'metadata' => [
                'amount' => 5000,
                'mode' => 'upi',
                'transaction_id' => 'UPI987654321',
            ],
        ]);

        $resPayment->assertStatus(201);

        // Verify lead payment status and amount updated automatically
        $leadFresh = $lead->fresh();
        $this->assertEquals(5000, (float) $leadFresh->amount_paid);
        $this->assertEquals('partial', $leadFresh->payment_status);
    }

    public function test_follow_up_scheduling_and_completion(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);
        $lead = Enquiry::create([
            'name' => 'Vikram Patel',
            'email' => 'vikram@example.com',
            'phone' => '8877665544',
            'status' => Enquiry::STATUS_NEW,
        ]);

        Sanctum::actingAs($counsellor);

        // 1. Schedule a follow-up
        $scheduledDate = now()->addDays(1)->setTime(15, 30);
        $resSchedule = $this->postJson("/api/admin/crm/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => $scheduledDate->toISOString(),
            'title' => 'Follow up regarding demo class feedback',
            'notes' => 'Candidate attended live demo on Saturday, check if ready for enrollment.',
        ]);

        $resSchedule->assertStatus(201);
        $followUpId = $resSchedule->json('follow_up.id');

        $this->assertDatabaseHas('crm_follow_ups', [
            'id' => $followUpId,
            'enquiry_id' => $lead->id,
            'status' => 'pending',
        ]);

        // Lead next_follow_up_date updated
        $this->assertEquals($scheduledDate->toDateString(), $lead->fresh()->next_follow_up_date->toDateString());

        // 2. Mark follow-up as completed with outcome notes
        $resComplete = $this->putJson("/api/admin/crm/follow-ups/{$followUpId}", [
            'status' => 'completed',
            'outcome' => 'Candidate agreed to join the morning batch starting next Monday.',
        ]);

        $resComplete->assertStatus(200);

        $this->assertDatabaseHas('crm_follow_ups', [
            'id' => $followUpId,
            'status' => 'completed',
            'outcome' => 'Candidate agreed to join the morning batch starting next Monday.',
        ]);

        // Verify completion activity logged on timeline
        $this->assertDatabaseHas('crm_activities', [
            'enquiry_id' => $lead->id,
            'activity_type' => 'follow_up',
            'title' => 'Completed follow-up: Follow up regarding demo class feedback',
        ]);
    }

    public function test_crm_to_lms_conversion_workflow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor', 'name' => 'Professor David']);
        $course = $this->createCourse(['title' => 'Kubernetes & Cloud Native', 'code' => 'K8S']);

        // Create cohort batch for course: RIT(K8S)BC010926
        $batch = Batch::create([
            'name' => 'K8S September Cohort',
            'code' => 'RIT(K8S)BC010926',
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'start_date' => '2026-09-01',
            'status' => 'upcoming',
            'max_students' => 25,
        ]);

        $lead = Enquiry::create([
            'name' => 'Sunil Gupta',
            'email' => 'sunil.gupta@example.com',
            'phone' => '+91 9123456789',
            'course_id' => $course->id,
            'status' => Enquiry::STATUS_ADMISSION_CONFIRMED,
            'expected_revenue' => 35000,
            'amount_paid' => 5000,
        ]);

        Sanctum::actingAs($admin);

        // Execute CRM -> LMS Conversion
        $resConvert = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
            'batch_id' => $batch->id,
            'name' => 'Sunil Gupta',
            'email' => 'sunil.gupta@example.com',
            'amount_paid' => 35000,
            'payment_mode' => 'netbanking',
            'transaction_id' => 'HDFC12345678',
            'notes' => 'Admission confirmed with full fees paid.',
        ]);

        $resConvert->assertStatus(200);

        // 1. Verify student user account provisioned
        $this->assertDatabaseHas('users', [
            'email' => 'sunil.gupta@example.com',
            'name' => 'Sunil Gupta',
            'role' => 'student',
            'status' => 'active',
        ]);
        $studentUser = User::where('email', 'sunil.gupta@example.com')->first();
        $this->assertNotNull($studentUser->student_id);

        // 2. Verify active course enrollment created
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $studentUser->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        // 3. Verify student assigned to batch cohort
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        // 4. Verify batch transfer audit record
        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $studentUser->id,
            'to_batch_id' => $batch->id,
            'action_type' => 'enrolled',
        ]);

        // 5. Verify lead status updated to converted
        $this->assertDatabaseHas('enquiries', [
            'id' => $lead->id,
            'status' => Enquiry::STATUS_CONVERTED,
            'enrolled_user_id' => $studentUser->id,
            'payment_status' => 'paid',
        ]);

        // 6. Verify conversion activity logged on timeline
        $this->assertDatabaseHas('crm_activities', [
            'enquiry_id' => $lead->id,
            'activity_type' => 'conversion',
            'title' => "Converted to Enrolled Student ({$studentUser->student_id})",
        ]);
    }

    public function test_crm_to_lms_conversion_prevents_duplicate_student_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $existingStudent = User::factory()->create([
            'email' => 'existing.learner@example.com',
            'name' => 'Existing Learner',
            'role' => 'student',
            'student_id' => 'STU-1099',
        ]);

        $course = $this->createCourse(['title' => 'Python Analytics']);

        $lead = Enquiry::create([
            'name' => 'Existing Learner',
            'email' => 'existing.learner@example.com',
            'phone' => '7766554433',
            'course_id' => $course->id,
            'status' => Enquiry::STATUS_INTERESTED,
        ]);

        Sanctum::actingAs($admin);

        // Execute conversion for existing student
        $res = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
            'email' => 'existing.learner@example.com',
        ]);

        $res->assertStatus(200);

        // Verify NO duplicate user was created
        $this->assertEquals(1, User::where('email', 'existing.learner@example.com')->count());

        // Verify enrollment linked to existing student ID
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $existingStudent->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    public function test_single_active_session_enforcement_on_crm_endpoints(): void
    {
        $counsellor = User::factory()->create([
            'role' => 'counsellor',
            'current_session_id' => 'session_active_now',
        ]);

        // Token created with old/revoked session ID
        $revokedToken = $counsellor->createToken('old_device', ['session:session_revoked_old'])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $revokedToken)
            ->getJson('/api/admin/crm/leads');

        $res->assertStatus(401)
            ->assertJsonFragment([
                'code' => 'SESSION_REVOKED',
            ]);
    }
}
