<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\MockInterview;
use App\Models\MockInterviewEvaluation;
use App\Models\MockInterviewer;
use App\Models\MockInterviewSlot;
use App\Models\PlacementOpportunity;
use App\Models\StudentPlacementEligibility;
use App\Models\User;
use App\Services\MockInterviewService;
use App\Services\PlacementSettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MockInterviewFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Placement Dean',
            'email' => 'dean@masterintech.com',
            'role' => 'admin',
            'current_session_id' => 'admin_session_' . Str::random(10),
        ]);
    }

    private function createStudent(string $name = 'Rahul Sharma', bool $completedCourse = false): array
    {
        $course = Course::create([
            'title' => 'Cloud Native DevSecOps',
            'slug' => 'devsecops-' . Str::random(6),
            'code' => 'DSO',
            'description' => 'Comprehensive DevSecOps Engineering.',
            'category' => 'Engineering',
            'instructor' => 'Lead DevOps Architect',
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        $batch = Batch::create([
            'name' => 'DevOps Cohort ' . Str::random(4),
            'code' => 'RIT(DO)BC' . strtoupper(Str::random(6)),
            'course_id' => $course->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $student = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name) . '@student.com',
            'phone' => '+91 9876500001',
            'student_id' => 'STU-' . Str::upper(Str::random(5)),
            'role' => 'student',
            'current_session_id' => 'student_session_' . Str::random(10),
        ]);

        $enrollment = CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => $completedCourse ? 'completed' : 'active',
            'progress_percentage' => $completedCourse ? 100.0 : 25.0,
            'enrolled_at' => now(),
        ]);

        BatchStudent::create([
            'user_id' => $student->id,
            'batch_id' => $batch->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $section = \App\Models\Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1: Cloud Security Fundamentals',
            'order' => 1,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Kubernetes Security & RBAC',
            'slug' => 'k8s-security-' . Str::random(5),
            'is_published' => true,
            'order' => 1,
        ]);

        if ($completedCourse) {
            LessonProgress::create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'section_id' => $section->id,
                'lesson_id' => $lesson->id,
                'completed' => true,
                'completed_at' => now(),
                'progress_percentage' => 100.0,
                'started' => true,
                'status' => 'completed',
            ]);
        }

        return [
            'student' => $student,
            'course' => $course,
            'batch' => $batch,
            'enrollment' => $enrollment,
            'lesson' => $lesson,
        ];
    }

    private function createInterviewer(string $name = 'Arjun Verma'): MockInterviewer
    {
        return MockInterviewer::create([
            'name' => $name,
            'email' => Str::slug($name) . '@interviews.com',
            'phone' => '+91 9988776655',
            'designation' => 'Principal Cloud Architect',
            'company' => 'Google Cloud',
            'years_of_experience' => 10.5,
            'skills' => ['Kubernetes', 'Docker', 'AWS', 'CI/CD', 'Terraform', 'Golang'],
            'bio' => '10+ years architecting scalable cloud distributed systems.',
            'internal_notes' => 'Highly recommended for backend & DevOps mock interviews.',
            'is_active' => true,
        ]);
    }

    private function createSlot(MockInterviewer $interviewer, ?string $date = null, ?string $startTime = '14:00'): MockInterviewSlot
    {
        $slotDate = $date ?? Carbon::tomorrow()->toDateString();

        return MockInterviewSlot::create([
            'interviewer_id' => $interviewer->id,
            'slot_date' => $slotDate,
            'start_time' => $startTime,
            'end_time' => Carbon::parse($startTime)->addMinutes(45)->format('H:i'),
            'duration_minutes' => 45,
            'meeting_link' => 'https://meet.google.com/mit-mock-test',
            'platform' => 'Google Meet',
            'status' => MockInterviewSlot::STATUS_AVAILABLE,
            'instructions' => 'Please join 5 minutes before scheduled time with camera enabled.',
        ]);
    }

    public function test_student_course_completion_eligibility_breakdown(): void
    {
        // 1. Incomplete student -> Ineligible
        $ineligibleData = $this->createStudent('Pooja Nair', false);
        $ineligibleStudent = $ineligibleData['student'];

        Sanctum::actingAs($ineligibleStudent);
        $resIneligible = $this->getJson('/api/student/mock-interviews/eligibility');
        $resIneligible->assertStatus(200)
            ->assertJson([
                'is_eligible' => false,
                'course_completed' => false,
                'mock_interview_state' => 'not_scheduled',
                'placement_eligible' => false,
            ]);

        // 2. Completed student -> Eligible
        $eligibleData = $this->createStudent('Rohan Gupta', true);
        $eligibleStudent = $eligibleData['student'];

        Sanctum::actingAs($eligibleStudent);
        $resEligible = $this->getJson('/api/student/mock-interviews/eligibility');
        $resEligible->assertStatus(200)
            ->assertJson([
                'is_eligible' => true,
                'course_completed' => true,
                'placement_eligible' => false,
            ]);
    }

    public function test_ineligible_student_cannot_book_slot(): void
    {
        $ineligibleData = $this->createStudent('Manoj Kumar', false);
        $ineligibleStudent = $ineligibleData['student'];

        $interviewer = $this->createInterviewer();
        $slot = $this->createSlot($interviewer);

        Sanctum::actingAs($ineligibleStudent);
        $res = $this->postJson('/api/student/mock-interviews/book', [
            'slot_id' => $slot->id,
        ]);

        $res->assertStatus(422);
        $this->assertEquals(MockInterviewSlot::STATUS_AVAILABLE, $slot->fresh()->status);
    }

    public function test_admin_can_manage_interviewers_and_public_safe_projection(): void
    {
        $admin = $this->createAdmin();
        $studentData = $this->createStudent('Kavita Krishnan', true);
        $student = $studentData['student'];

        Sanctum::actingAs($admin);

        // 1. Admin creates interviewer
        $resCreate = $this->postJson('/api/admin/mock-interviews/interviewers', [
            'name' => 'Deepak Chopra',
            'email' => 'deepak@techlead.com',
            'phone' => '+91 9123456789',
            'designation' => 'Director of Engineering',
            'company' => 'Microsoft',
            'years_of_experience' => 14,
            'skills' => ['System Design', 'Distributed Systems', 'C#', '.NET Core'],
            'bio' => 'Experienced engineering leader.',
            'internal_notes' => 'Internal rating: 5/5',
            'is_active' => true,
        ]);

        $resCreate->assertStatus(201)
            ->assertJsonFragment(['name' => 'Deepak Chopra', 'company' => 'Microsoft']);

        $interviewerId = $resCreate->json('interviewer.id');
        $interviewer = MockInterviewer::find($interviewerId);
        $this->assertNotNull($interviewer);

        // Create a slot for this interviewer
        $slot = $this->createSlot($interviewer);

        // 2. Student queries available slots -> private phone & internal_notes are masked
        Sanctum::actingAs($student);
        $resSlots = $this->getJson('/api/student/mock-interviews/slots');
        $resSlots->assertStatus(200);

        $slotsArray = $resSlots->json();
        $this->assertNotEmpty($slotsArray);
        $firstSlot = $slotsArray[0];

        $this->assertEquals('Deepak Chopra', $firstSlot['interviewer']['name']);
        $this->assertEquals('Microsoft', $firstSlot['interviewer']['company']);
        $this->assertArrayNotHasKey('phone', $firstSlot['interviewer']);
        $this->assertArrayNotHasKey('internal_notes', $firstSlot['interviewer']);
    }

    public function test_eligible_student_can_book_available_slot(): void
    {
        $studentData = $this->createStudent('Vikram Joshi', true);
        $student = $studentData['student'];
        $course = $studentData['course'];
        $batch = $studentData['batch'];

        $interviewer = $this->createInterviewer('Suresh Menon');
        $slot = $this->createSlot($interviewer);

        Sanctum::actingAs($student);
        $resBook = $this->postJson('/api/student/mock-interviews/book', [
            'slot_id' => $slot->id,
            'student_notes' => 'Focus on Kubernetes, Helm charts, and CI/CD pipelines.',
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ]);

        $resBook->assertStatus(201)
            ->assertJsonFragment([
                'status' => MockInterview::STATUS_BOOKED,
                'student_notes' => 'Focus on Kubernetes, Helm charts, and CI/CD pipelines.',
            ]);

        $this->assertEquals(MockInterviewSlot::STATUS_BOOKED, $slot->fresh()->status);
        $this->assertDatabaseHas('mock_interviews', [
            'student_id' => $student->id,
            'slot_id' => $slot->id,
            'interviewer_id' => $interviewer->id,
            'status' => MockInterview::STATUS_BOOKED,
        ]);
    }

    public function test_duplicate_and_concurrent_slot_booking_protection(): void
    {
        $student1Data = $this->createStudent('Candidate One', true);
        $student1 = $student1Data['student'];

        $student2Data = $this->createStudent('Candidate Two', true);
        $student2 = $student2Data['student'];

        $interviewer = $this->createInterviewer();
        $slot = $this->createSlot($interviewer);

        // Student 1 books slot
        Sanctum::actingAs($student1);
        $res1 = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot->id]);
        $res1->assertStatus(201);

        // Student 2 attempts to book same slot -> 422 Rejected
        Sanctum::actingAs($student2);
        $res2 = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot->id]);
        $res2->assertStatus(422);
    }

    public function test_single_active_booking_enforcement_per_student(): void
    {
        $studentData = $this->createStudent('Ankit Agrawal', true);
        $student = $studentData['student'];

        $interviewer = $this->createInterviewer();
        $slot1 = $this->createSlot($interviewer, Carbon::tomorrow()->toDateString(), '10:00');
        $slot2 = $this->createSlot($interviewer, Carbon::tomorrow()->toDateString(), '16:00');

        Sanctum::actingAs($student);

        // First booking succeeds
        $res1 = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot1->id]);
        $res1->assertStatus(201);

        // Second booking attempt while first is active -> 422
        $res2 = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot2->id]);
        $res2->assertStatus(422);
    }

    public function test_student_and_admin_cancellation_and_rescheduling(): void
    {
        $studentData = $this->createStudent('Sneha Patil', true);
        $student = $studentData['student'];

        $interviewer = $this->createInterviewer();
        $slot1 = $this->createSlot($interviewer, Carbon::tomorrow()->toDateString(), '11:00');
        $slot2 = $this->createSlot($interviewer, Carbon::tomorrow()->toDateString(), '15:00');

        Sanctum::actingAs($student);
        $resBook = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot1->id]);
        $interviewId = $resBook->json('interview.id');
        $this->assertEquals(MockInterviewSlot::STATUS_BOOKED, $slot1->fresh()->status);

        // 1. Reschedule to slot2
        $resReschedule = $this->postJson("/api/student/mock-interviews/{$interviewId}/reschedule", [
            'slot_id' => $slot2->id,
            'reason' => 'Schedule conflict at 11:00 AM.',
        ]);
        $resReschedule->assertStatus(200);

        $this->assertEquals(MockInterviewSlot::STATUS_AVAILABLE, $slot1->fresh()->status);
        $this->assertEquals(MockInterviewSlot::STATUS_BOOKED, $slot2->fresh()->status);

        // 2. Cancel the booking
        $resCancel = $this->postJson("/api/student/mock-interviews/{$interviewId}/cancel", [
            'reason' => 'Need to prepare more on system design.',
        ]);
        $resCancel->assertStatus(200)
            ->assertJsonFragment(['status' => MockInterview::STATUS_CANCELLED]);

        $this->assertEquals(MockInterviewSlot::STATUS_AVAILABLE, $slot2->fresh()->status);
    }

    public function test_mock_interview_evaluation_scorecard_and_placement_eligibility_transition(): void
    {
        $admin = $this->createAdmin();
        $studentData = $this->createStudent('Tarun Nair', true);
        $student = $studentData['student'];

        $interviewer = $this->createInterviewer('Naveen Reddy');
        $slot = $this->createSlot($interviewer);

        Sanctum::actingAs($student);
        $resBook = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot->id]);
        $interviewId = $resBook->json('interview.id');
        $interview = MockInterview::find($interviewId);

        // Initially student is not placement eligible
        $this->assertFalse(MockInterviewService::isStudentPlacementEligible($student));

        // Admin/Interviewer submits passing evaluation scorecard ("Ready for Placement")
        Sanctum::actingAs($admin);
        $resEval = $this->postJson("/api/admin/mock-interviews/bookings/{$interview->id}/evaluate", [
            'technical_knowledge' => 9,
            'programming_problem_solving' => 8,
            'communication' => 9,
            'confidence' => 8,
            'project_knowledge' => 9,
            'interview_readiness' => 9,
            'overall_rating' => 8.7,
            'strengths' => 'Exceptional clarity on microservices, Docker, Kubernetes deployments.',
            'areas_for_improvement' => 'Deepen knowledge in Prometheus monitoring and Grafana alerting.',
            'interviewer_remarks' => 'Outstanding candidate. Strong hire for Tier 1 tech companies.',
            'recommendation' => MockInterviewEvaluation::REC_READY_FOR_PLACEMENT,
            'is_published_to_student' => true,
        ]);

        $resEval->assertStatus(201)
            ->assertJsonFragment(['recommendation' => 'Ready for Placement']);

        $this->assertEquals(MockInterview::STATUS_COMPLETED, $interview->fresh()->status);
        $this->assertEquals(MockInterviewSlot::STATUS_COMPLETED, $slot->fresh()->status);

        // Student is now unlocked and Placement Eligible!
        $this->assertTrue(MockInterviewService::isStudentPlacementEligible($student));

        // Verify student can view their completed evaluation
        Sanctum::actingAs($student);
        $resMyInterviews = $this->getJson('/api/student/mock-interviews/my-interviews');
        $resMyInterviews->assertStatus(200)
            ->assertJsonFragment(['recommendation' => 'Ready for Placement']);
    }

    public function test_evaluation_with_needs_improvement_does_not_grant_placement_eligibility(): void
    {
        $admin = $this->createAdmin();
        $studentData = $this->createStudent('Akash Patel', true);
        $student = $studentData['student'];

        $interviewer = $this->createInterviewer();
        $slot = $this->createSlot($interviewer);

        Sanctum::actingAs($student);
        $resBook = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot->id]);
        $interviewId = $resBook->json('interview.id');
        $interview = MockInterview::find($interviewId);

        // Evaluated with "Needs Improvement"
        Sanctum::actingAs($admin);
        $resEval = $this->postJson("/api/admin/mock-interviews/bookings/{$interview->id}/evaluate", [
            'technical_knowledge' => 4,
            'programming_problem_solving' => 4,
            'communication' => 5,
            'confidence' => 4,
            'project_knowledge' => 5,
            'interview_readiness' => 4,
            'overall_rating' => 4.3,
            'strengths' => 'Good theoretical grasp.',
            'areas_for_improvement' => 'Needs significant hands-on coding and problem-solving practice.',
            'recommendation' => MockInterviewEvaluation::REC_NEEDS_IMPROVEMENT,
        ]);

        $resEval->assertStatus(201);
        $this->assertFalse(MockInterviewService::isStudentPlacementEligible($student));
    }

    public function test_placement_job_application_gating_behind_mock_interview_readiness(): void
    {
        $studentData = $this->createStudent('Geeta Roy', true);
        $student = $studentData['student'];
        $batch = $studentData['batch'];

        $opportunity = PlacementOpportunity::create([
            'title' => 'Site Reliability Engineer',
            'company_name' => 'InfraScale Global',
            'location' => 'Bengaluru, India',
            'employment_type' => 'Full-time',
            'status' => PlacementOpportunity::STATUS_PUBLISHED,
            'description' => 'Cloud infrastructure and SRE role.',
        ]);

        // Placement & mock interview required flags are enabled
        $this->assertTrue(PlacementSettingService::isPlacementEnabled());
        $this->assertTrue(PlacementSettingService::isMockInterviewRequired());

        // 1. Student without completed mock interview tries to apply -> 403 MOCK_INTERVIEW_REQUIRED
        Sanctum::actingAs($student);
        $resApply1 = $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_number' => $batch->code,
            'phone' => '+91 9876500001',
            'resume_url' => 'https://example.com/geeta-cv.pdf',
        ]);
        $resApply1->assertStatus(403)
            ->assertJsonFragment(['code' => 'MOCK_INTERVIEW_REQUIRED']);

        // 2. Admin grants placement eligibility override
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/mock-interviews/eligibility-override', [
            'student_id' => $student->id,
            'placement_eligible' => true,
            'reason' => 'Prior industry experience & portfolio approved by Placement Dean.',
        ])->assertStatus(200);

        // 3. Student can now successfully apply!
        Sanctum::actingAs($student);
        $resApply2 = $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_number' => $batch->code,
            'phone' => '+91 9876500001',
            'resume_url' => 'https://example.com/geeta-cv.pdf',
        ]);
        $resApply2->assertStatus(201)
            ->assertJsonFragment(['student_name' => 'Geeta Roy']);
    }

    public function test_authorization_rules_and_security(): void
    {
        $student1Data = $this->createStudent('Student Alpha', true);
        $student1 = $student1Data['student'];

        $student2Data = $this->createStudent('Student Beta', true);
        $student2 = $student2Data['student'];

        $interviewer = $this->createInterviewer();
        $slot = $this->createSlot($interviewer);

        Sanctum::actingAs($student1);
        $resBook = $this->postJson('/api/student/mock-interviews/book', ['slot_id' => $slot->id]);
        $interviewId = $resBook->json('interview.id');

        // Student 2 attempts to view Student 1's interview -> 403 Forbidden
        Sanctum::actingAs($student2);
        $this->getJson("/api/student/mock-interviews/{$interviewId}")->assertStatus(403);
        $this->postJson("/api/student/mock-interviews/{$interviewId}/cancel", ['reason' => 'Unauthorized'])->assertStatus(403);

        // Student cannot access admin mock interview routes -> 403 Forbidden
        $this->getJson('/api/admin/mock-interviews/stats')->assertStatus(403);
        $this->postJson('/api/admin/mock-interviews/interviewers', ['name' => 'Unauthorized'])->assertStatus(403);
    }
}
