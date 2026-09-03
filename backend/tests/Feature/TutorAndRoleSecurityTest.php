<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TutorAndRoleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_access_tutor_or_admin_routes(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        // Student tries accessing tutor stats
        $response = $this->getJson('/api/tutor/stats');
        $response->assertStatus(403);

        // Student tries accessing admin stats
        $response = $this->getJson('/api/admin/stats');
        $response->assertStatus(403);

        // Student tries accessing admin users
        $response = $this->getJson('/api/admin/users');
        $response->assertStatus(403);
    }

    public function test_tutor_cannot_access_admin_routes(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        // Tutor tries accessing admin stats
        $response = $this->getJson('/api/admin/stats');
        $response->assertStatus(403);

        // Tutor tries accessing admin users list
        $response = $this->getJson('/api/admin/users');
        $response->assertStatus(403);

        // Tutor tries creating event in admin
        $response = $this->postJson('/api/admin/events', []);
        $response->assertStatus(403);
    }

    public function test_tutor_can_manage_own_courses_and_curriculum(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        // Tutor cannot create course (Goal 6: Admin only)
        $response = $this->postJson('/api/tutor/courses', [
            'title' => 'Advanced Next.js 15 & AI',
            'description' => 'Build high-speed modern apps with server actions and AI.',
            'category' => 'Full Stack Development',
            'duration' => '6 weeks',
            'difficulty' => 'Advanced',
        ]);
        $response->assertStatus(403);

        // Course assigned to tutor by admin
        $course = Course::create([
            'title' => 'Advanced Next.js 15 & AI',
            'slug' => 'advanced-next-js-15-ai',
            'description' => 'Build high-speed modern apps with server actions and AI.',
            'category' => 'Full Stack Development',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '6 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);
        $courseId = $course->id;

        // Tutor gets tutor stats
        $stats = $this->getJson('/api/tutor/stats');
        $stats->assertStatus(200)
            ->assertJsonFragment(['total_courses' => 1]);

        // Tutor adds section to own course
        $secRes = $this->postJson("/api/courses/{$courseId}/sections", [
            'title' => 'Module 1: Server Components',
            'sort_order' => 1,
        ]);
        $secRes->assertStatus(201);
        $sectionId = $secRes->json('id');

        // Tutor adds lesson to own section
        $lesRes = $this->postJson("/api/courses/{$courseId}/sections/{$sectionId}/lessons", [
            'title' => '1.1 Architecture Overview',
            'duration' => '15 min',
            'type' => 'video',
            'sort_order' => 1,
            'metadata' => ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ]);
        $lesRes->assertStatus(201);
    }

    public function test_tutor_cannot_modify_another_tutors_course(): void
    {
        $tutor1 = User::factory()->create(['role' => 'tutor']);
        $tutor2 = User::factory()->create(['role' => 'tutor']);

        $course = Course::create([
            'title' => 'Tutor 1 Secret Course',
            'slug' => 'tutor-1-secret-course',
            'description' => 'Owned by tutor 1',
            'instructor' => $tutor1->name,
            'instructor_id' => $tutor1->id,
            'price' => 9999,
            'duration' => '4 weeks',
            'difficulty' => 'Intermediate',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'sort_order' => 1,
        ]);

        // Acting as Tutor 2
        Sanctum::actingAs($tutor2);

        // Tutor 2 tries to update Tutor 1 course
        $res = $this->putJson("/api/tutor/courses/{$course->id}", [
            'title' => 'Hacked Course Name',
        ]);
        $res->assertStatus(403);

        // Tutor 2 tries to add section to Tutor 1 course
        $res = $this->postJson("/api/courses/{$course->id}/sections", [
            'title' => 'Malicious Section',
        ]);
        $res->assertStatus(403);

        // Tutor 2 tries to add lesson to Tutor 1 course
        $res = $this->postJson("/api/courses/{$course->id}/sections/{$section->id}/lessons", [
            'title' => 'Malicious Lesson',
            'type' => 'text',
        ]);
        $res->assertStatus(403);
    }

    public function test_admin_can_manage_users_and_assign_roles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($admin);

        // Admin stats
        $stats = $this->getJson('/api/admin/stats');
        $stats->assertStatus(200)
            ->assertJsonStructure(['total_users', 'total_students', 'total_tutors', 'total_admins']);

        // Admin lists users
        $users = $this->getJson('/api/admin/users');
        $users->assertStatus(200);

        // Admin promotes student to tutor
        $promote = $this->putJson("/api/admin/users/{$student->id}/role", [
            'role' => 'tutor',
        ]);
        $promote->assertStatus(200);

        $this->assertEquals('tutor', $student->fresh()->role);
    }

    public function test_tutor_can_view_analytics_and_manage_quiz_and_assignment(): void
    {
        $tutor = User::factory()->create([
            'role' => 'tutor',
            'permissions' => [
                'create_quizzes' => true,
                'edit_quizzes' => true,
                'view_assigned_courses' => true,
            ],
        ]);
        Sanctum::actingAs($tutor);

        $course = Course::create([
            'title' => 'Tutor Analytics & Quiz Course',
            'slug' => 'tutor-analytics-course',
            'description' => 'Owned by tutor',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'price' => 5999,
            'duration' => '4 weeks',
            'difficulty' => 'Intermediate',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'sort_order' => 1,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Lesson 1',
            'type' => 'video',
            'sort_order' => 1,
        ]);

        // Tutor views course analytics
        $analytics = $this->getJson("/api/tutor/courses/{$course->id}/analytics");
        $analytics->assertStatus(200)
            ->assertJsonStructure(['total_enrolled', 'in_progress', 'completed', 'average_rating', 'submissions_count']);

        // Tutor saves quiz on own lesson
        $quizRes = $this->postJson("/api/tutor/courses/{$course->id}/lessons/{$lesson->id}/quiz", [
            'title' => 'Lesson 1 Checkpoint',
            'passing_score' => 75,
            'time_limit' => 20,
            'questions' => [
                [
                    'question' => 'What is 2 + 2?',
                    'marks' => 5,
                    'options' => [
                        ['option_text' => '4', 'is_correct' => true],
                        ['option_text' => '5', 'is_correct' => false],
                    ],
                ],
            ],
        ]);
        $quizRes->assertStatus(200);

        // Tutor saves assignment on own lesson
        $assignRes = $this->postJson("/api/tutor/courses/{$course->id}/lessons/{$lesson->id}/assignment", [
            'title' => 'Module 1 Capstone',
            'instructions' => 'Build a full stack API',
            'max_marks' => 100,
        ]);
        $assignRes->assertStatus(200);
    }

    public function test_tutor_cannot_view_analytics_or_add_quiz_for_another_tutors_course(): void
    {
        $tutor1 = User::factory()->create(['role' => 'tutor']);
        $tutor2 = User::factory()->create(['role' => 'tutor']);

        $course = Course::create([
            'title' => 'Tutor 1 Course',
            'slug' => 'tutor-1-course',
            'description' => 'Owned by tutor 1',
            'instructor' => $tutor1->name,
            'instructor_id' => $tutor1->id,
            'price' => 4999,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'sort_order' => 1,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Lesson 1',
            'type' => 'video',
            'sort_order' => 1,
        ]);

        // Acting as Tutor 2
        Sanctum::actingAs($tutor2);

        // Analytics forbidden
        $res = $this->getJson("/api/tutor/courses/{$course->id}/analytics");
        $res->assertStatus(403);

        // Quiz forbidden
        $res2 = $this->postJson("/api/tutor/courses/{$course->id}/lessons/{$lesson->id}/quiz", [
            'title' => 'Unauthorized Quiz',
            'passing_score' => 50,
            'questions' => [
                [
                    'question' => 'Q1',
                    'marks' => 5,
                    'options' => [
                        ['option_text' => 'A', 'is_correct' => true],
                        ['option_text' => 'B', 'is_correct' => false],
                    ],
                ],
            ],
        ]);
        $res2->assertStatus(403);
    }

    public function test_public_user_cannot_register_directly_and_must_be_provisioned_by_admin(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alice Student',
            'email' => 'alice.student@example.com',
            'password' => 'secret123',
        ]);

        // Public registration is disabled under Goal 3A
        $response->assertStatus(403);

        $user = User::where('email', 'alice.student@example.com')->first();
        $this->assertNull($user);
    }

    public function test_public_user_cannot_register_as_tutor(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Fake Tutor',
            'email' => 'fake.tutor@example.com',
            'password' => 'secret123',
            'role' => 'tutor',
        ]);

        // Public registration is disabled (403)
        $response->assertStatus(403);

        $user = User::where('email', 'fake.tutor@example.com')->first();
        $this->assertNull($user);
    }

    public function test_public_user_cannot_register_as_admin(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Hacker Admin',
            'email' => 'hacker@example.com',
            'password' => 'secret123',
            'role' => 'admin',
        ]);

        // Public registration is disabled (403)
        $response->assertStatus(403);

        $user = User::where('email', 'hacker@example.com')->first();
        $this->assertNull($user);
    }

    public function test_admin_can_create_tutor_with_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Dr. Robert Smith',
            'email' => 'robert.smith@example.com',
            'password' => 'secret123',
            'role' => 'tutor',
            'phone' => '+91 9988776655',
            'headline' => 'AI Architect & Instructor',
            'expertise' => 'Machine Learning, PyTorch, Python',
            'bio' => 'Teaching applied AI for 8+ years.',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role']]);

        $user = User::where('email', 'robert.smith@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('tutor', $user->role);
        $this->assertEquals('AI Architect & Instructor', $user->headline);
        $this->assertEquals('Machine Learning, PyTorch, Python', $user->expertise);
    }

    public function test_non_admin_cannot_create_tutor(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Unauthorized Tutor',
            'email' => 'unauth.tutor@example.com',
            'password' => 'secret123',
            'role' => 'tutor',
        ]);

        $response->assertStatus(403);
    }

    public function test_single_login_endpoint_authenticates_student_tutor_and_admin(): void
    {
        $student = User::factory()->create([
            'email' => 'student.login@example.com',
            'password' => bcrypt('password123'),
            'role' => 'student',
        ]);

        $tutor = User::factory()->create([
            'email' => 'tutor.login@example.com',
            'password' => bcrypt('password123'),
            'role' => 'tutor',
        ]);

        $admin = User::factory()->create([
            'email' => 'admin.login@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        // 1. Student Login
        $resStudent = $this->postJson('/api/login', [
            'email' => 'student.login@example.com',
            'password' => 'password123',
        ]);
        $resStudent->assertStatus(200)
            ->assertJsonFragment(['role' => 'student']);

        // 2. Tutor Login
        $resTutor = $this->postJson('/api/login', [
            'email' => 'tutor.login@example.com',
            'password' => 'password123',
        ]);
        $resTutor->assertStatus(200)
            ->assertJsonFragment(['role' => 'tutor']);

        // 3. Admin Login
        $resAdmin = $this->postJson('/api/login', [
            'email' => 'admin.login@example.com',
            'password' => 'password123',
        ]);
        $resAdmin->assertStatus(200)
            ->assertJsonFragment(['role' => 'admin']);
    }

    public function test_tutor_cannot_create_own_course_and_only_admin_can(): void
    {
        $tutor = User::factory()->create(['name' => 'Prof. Alex', 'role' => 'tutor']);
        Sanctum::actingAs($tutor);

        // Attempting to create course as tutor -> 403 Forbidden
        $response = $this->postJson('/api/tutor/courses', [
            'title' => 'Distributed Microservices in Go',
            'description' => 'Master high scale distributed systems.',
            'category' => 'Backend Engineering',
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'status' => 'published',
        ]);
        $response->assertStatus(403);
    }

    public function test_tutor_cannot_delete_course(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        $course = Course::create([
            'title' => 'Temporary Course',
            'slug' => 'temp-course',
            'description' => 'To be deleted',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'price' => 1000,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
        ]);

        $response = $this->deleteJson("/api/tutor/courses/{$course->id}");
        $response->assertStatus(403);
    }

    public function test_tutor_cannot_delete_another_tutors_course(): void
    {
        $tutor1 = User::factory()->create(['role' => 'tutor']);
        $tutor2 = User::factory()->create(['role' => 'tutor']);

        $course = Course::create([
            'title' => 'Tutor 1 Permanent Course',
            'slug' => 'tutor-1-perm-course',
            'description' => 'Belongs to tutor 1',
            'instructor' => $tutor1->name,
            'instructor_id' => $tutor1->id,
            'price' => 5000,
            'duration' => '3 weeks',
            'difficulty' => 'Intermediate',
        ]);

        Sanctum::actingAs($tutor2);

        $response = $this->deleteJson("/api/tutor/courses/{$course->id}");
        $response->assertStatus(403);

        $this->assertNotNull(Course::find($course->id));
    }

    public function test_student_cannot_access_tutor_course_management_endpoints(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        // Student tries to list tutor courses
        $this->getJson('/api/tutor/courses')->assertStatus(403);

        // Student tries to create course
        $this->postJson('/api/tutor/courses', [
            'title' => 'Student Unauthorized Course',
            'description' => 'Should fail',
            'price' => 0,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
        ])->assertStatus(403);
    }

    public function test_admin_retains_access_to_all_courses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        $course = Course::create([
            'title' => 'Tutor Course Managed by Admin',
            'slug' => 'tutor-course-admin',
            'description' => 'Managed by Admin',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'price' => 7000,
            'duration' => '5 weeks',
            'difficulty' => 'Intermediate',
        ]);

        Sanctum::actingAs($admin);

        // Admin can view course
        $this->getJson("/api/tutor/courses/{$course->id}")->assertStatus(200);

        $res = $this->putJson("/api/tutor/courses/{$course->id}", [
            'title' => 'Admin Updated Course Title',
        ]);
        $res->assertStatus(200);

        $this->assertEquals('Admin Updated Course Title', $course->fresh()->title);
    }

    public function test_public_and_student_course_responses_do_not_expose_price(): void
    {
        $course = Course::create([
            'title' => 'Public Hidden Price Course',
            'slug' => 'public-hidden-price',
            'description' => 'A course with internal pricing',
            'instructor' => 'Lead Faculty',
            'price' => 45000,
            'duration' => '10 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        // 1. Guest / Public Access
        $resPublicList = $this->getJson('/api/courses');
        $resPublicList->assertStatus(200);
        $courseData = collect($resPublicList->json())->firstWhere('id', $course->id);
        $this->assertArrayNotHasKey('price', $courseData);
        $this->assertArrayNotHasKey('internal_price', $courseData);

        $resPublicShow = $this->getJson("/api/courses/{$course->id}");
        $resPublicShow->assertStatus(200);
        $this->assertArrayNotHasKey('price', $resPublicShow->json());

        // 2. Student Access
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $resStudentShow = $this->getJson("/api/courses/{$course->id}");
        $resStudentShow->assertStatus(200);
        $this->assertArrayNotHasKey('price', $resStudentShow->json());
    }

    public function test_tutor_course_response_does_not_expose_price(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        $course = Course::create([
            'title' => 'Tutor Taught Hidden Price Course',
            'slug' => 'tutor-taught-hidden-price',
            'description' => 'A course authored by tutor',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'price' => 29999,
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        Sanctum::actingAs($tutor);

        // Tutor listing owned courses
        $res = $this->getJson('/api/tutor/courses');
        $res->assertStatus(200);
        $tutorCourse = collect($res->json())->firstWhere('id', $course->id);
        $this->assertArrayNotHasKey('price', $tutorCourse);

        // Tutor viewing single owned course
        $resShow = $this->getJson("/api/tutor/courses/{$course->id}");
        $resShow->assertStatus(200);
        $this->assertArrayNotHasKey('price', $resShow->json());
    }

    public function test_tutor_cannot_set_or_update_course_price(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        // Course created and assigned to tutor
        $course = Course::create([
            'title' => 'Tutor Proposed Course',
            'slug' => 'tutor-proposed-course',
            'description' => 'Curriculum without pricing power',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '6 weeks',
            'difficulty' => 'Beginner',
            'price' => 0.00,
            'is_published' => true,
        ]);

        // Tutor attempts to update course -> 403 Forbidden
        $resUpdate = $this->putJson("/api/tutor/courses/{$course->id}", [
            'title' => 'Tutor Proposed Course Updated',
            'price' => 50000,
        ]);
        $resUpdate->assertStatus(403);
    }

    public function test_admin_can_set_and_view_internal_course_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        // Admin creates course with internal price 24999
        $res = $this->postJson('/api/courses', [
            'title' => 'Enterprise SAP S/4HANA',
            'description' => 'Enterprise curriculum',
            'instructor' => 'Admin Team',
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'price' => 24999,
        ]);
        $res->assertStatus(201);
        $this->assertEquals(24999, $res->json('price'));

        $course = Course::where('title', 'Enterprise SAP S/4HANA')->first();

        // Admin views course
        $resShow = $this->getJson("/api/courses/{$course->id}");
        $resShow->assertStatus(200);
        $this->assertEquals(24999, $resShow->json('price'));
        $this->assertEquals(24999, $resShow->json('internal_price'));
    }

    public function test_public_course_enquiry_creates_lead_with_selected_course(): void
    {
        $course = Course::create([
            'title' => 'AI Engineer Bootcamp',
            'slug' => 'ai-engineer-bootcamp',
            'description' => 'Deep dive into LLMs',
            'instructor' => 'AI Mentors',
            'price' => 35000,
            'duration' => '16 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        $res = $this->postJson('/api/enquiries', [
            'name' => 'Kavita Sharma',
            'email' => 'kavita@example.com',
            'phone' => '+91 9876543210',
            'course_id' => $course->id,
            'preferred_time' => 'Weekend Afternoon',
            'message' => 'Interested in scholarship and demo class.',
        ]);

        $res->assertStatus(201)
            ->assertJsonFragment(['course_title' => 'AI Engineer Bootcamp', 'status' => 'new']);

        $this->assertDatabaseHas('enquiries', [
            'email' => 'kavita@example.com',
            'course_title' => 'AI Engineer Bootcamp',
        ]);
    }

    public function test_admin_can_view_and_update_course_enquiries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $enquiry = \App\Models\Enquiry::create([
            'name' => 'Rohan Mehta',
            'email' => 'rohan@example.com',
            'phone' => '+91 9123456789',
            'course_title' => 'Full Stack Web Development',
            'status' => 'new',
        ]);

        // Admin lists enquiries
        $res = $this->getJson('/api/admin/enquiries');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json());

        // Admin updates status to contacted
        $resUpdate = $this->putJson("/api/admin/enquiries/{$enquiry->id}", [
            'status' => 'contacted',
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonFragment(['status' => 'contacted']);

        $this->assertEquals('contacted', $enquiry->fresh()->status);
    }

    public function test_admin_can_admit_and_enroll_student(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $course = Course::create([
            'title' => 'Cloud Computing Track',
            'slug' => 'cloud-computing-track',
            'description' => 'AWS & DevOps',
            'instructor' => 'Lead DevOps',
            'duration' => '12 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        // Admin enrolls a new student lead via email
        $res = $this->postJson('/api/admin/enrollments', [
            'name' => 'New Admitted Student',
            'email' => 'admitted@example.com',
            'course_id' => $course->id,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('course_enrollments', [
            'course_id' => $course->id,
        ]);

        $studentUser = User::where('email', 'admitted@example.com')->first();
        $this->assertNotNull($studentUser);
        $this->assertEquals('student', $studentUser->role);
    }

    public function test_non_admin_cannot_access_admin_enrollment_endpoint(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        $course = Course::create([
            'title' => 'Security Demo Course',
            'slug' => 'security-demo-course',
            'description' => 'Security check',
            'instructor' => 'Admin',
            'duration' => '1 week',
            'difficulty' => 'Beginner',
        ]);

        // Student attempt
        Sanctum::actingAs($student);
        $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ])->assertStatus(403);

        // Tutor attempt
        Sanctum::actingAs($tutor);
        $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ])->assertStatus(403);
    }
}
