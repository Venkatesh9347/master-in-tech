<?php

namespace Tests\Feature;

use App\Mail\StudentLoginOtpMail;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the master-audit Batch 2 hardening fixes:
 *  - disabling a user revokes tokens + single-session marker (+ audit log)
 *  - OTP verification re-checks account status (no session for disabled users)
 *  - batches with member records cannot be hard-deleted
 *  - curriculum reorder cannot move a lesson into another course's section
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function makeCourse(string $title, string $slug): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => $slug,
            'description' => 'Regression fixture course.',
            'category' => 'Full Stack',
            'instructor' => 'Fixture Instructor',
            'duration' => '4 Weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);
    }

    public function test_disabling_user_revokes_tokens_and_session(): void
    {
        $admin = $this->makeAdmin();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $token = $student->startNewActiveSession('auth_token')->plainTextToken;
        $this->assertNotNull($student->fresh()->current_session_id);
        $this->assertSame(1, $student->tokens()->count());

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/users/{$student->id}", ['status' => 'disabled'])
            ->assertOk();

        $student->refresh();
        $this->assertSame('disabled', $student->status);
        $this->assertSame(0, $student->tokens()->count());
        $this->assertNull($student->current_session_id);
        $this->assertNull($student->current_session_created_at);

        // The previously issued Bearer must no longer authenticate.
        // (Forget the Sanctum::actingAs override first so the raw token is used.)
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', ['action' => 'updated_user']);
    }

    public function test_role_change_revokes_tokens_and_session(): void
    {
        $admin = $this->makeAdmin();
        $tutor = User::factory()->create(['role' => 'tutor', 'status' => 'active']);
        $tutor->startNewActiveSession('auth_token');
        $this->assertSame(1, $tutor->tokens()->count());

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/users/{$tutor->id}/role", ['role' => 'student'])
            ->assertOk();

        $tutor->refresh();
        $this->assertSame('student', $tutor->role);
        $this->assertSame(0, $tutor->tokens()->count());
        $this->assertNull($tutor->current_session_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated_user_role']);
    }

    public function test_otp_verification_rejected_for_disabled_account(): void
    {
        Mail::fake();

        User::factory()->create([
            'name' => 'Disabled Otp Student',
            'email' => 'disabled.otp@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        $googleRes = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:disabled.otp@example.com:Disabled Otp Student:google_sub_disabled_1',
        ]);
        $tempToken = $googleRes->json('temp_token');
        $this->assertNotNull($tempToken);

        $sentOtp = null;
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) use (&$sentOtp) {
            $sentOtp = $mail->otp;

            return true;
        });
        $this->assertNotNull($sentOtp);

        // Account is disabled after the OTP was dispatched.
        User::where('email', 'disabled.otp@example.com')->update(['status' => 'disabled']);

        $verifyRes = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => $sentOtp,
        ]);

        $verifyRes->assertStatus(422);
        $this->assertArrayNotHasKey('access_token', $verifyRes->json());
    }

    public function test_batch_delete_blocked_when_members_exist(): void
    {
        $admin = $this->makeAdmin();
        $course = $this->makeCourse('Batch Guard Course', 'batch-guard-course');
        $batch = Batch::create([
            'name' => 'Guarded Batch',
            'code' => 'RIT(GUARD)BC010126',
            'course_id' => $course->id,
            'start_date' => '2026-01-01',
            'status' => 'ongoing',
        ]);
        $student = User::factory()->create(['role' => 'student']);
        BatchStudent::create([
            'user_id' => $student->id,
            'batch_id' => $batch->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/batches/{$batch->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_batch_delete_allowed_when_empty(): void
    {
        $admin = $this->makeAdmin();
        $course = $this->makeCourse('Empty Batch Course', 'empty-batch-course');
        $batch = Batch::create([
            'name' => 'Empty Batch',
            'code' => 'RIT(EMPTY)BC010126',
            'course_id' => $course->id,
            'start_date' => '2026-01-01',
            'status' => 'upcoming',
        ]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/batches/{$batch->id}")
            ->assertOk();

        $this->assertDatabaseMissing('batches', ['id' => $batch->id]);
    }

    public function test_reorder_rejects_lesson_move_into_foreign_section(): void
    {
        $admin = $this->makeAdmin();
        $courseA = $this->makeCourse('Course Alpha', 'course-alpha');
        $courseB = $this->makeCourse('Course Beta', 'course-beta');

        $sectionA = Section::create([
            'course_id' => $courseA->id, 'title' => 'Alpha Module', 'sort_order' => 1, 'is_published' => true,
        ]);
        $sectionB = Section::create([
            'course_id' => $courseB->id, 'title' => 'Beta Module', 'sort_order' => 1, 'is_published' => true,
        ]);
        $lessonB = Lesson::create([
            'course_id' => $courseB->id, 'section_id' => $sectionB->id,
            'title' => 'Beta Lesson', 'sort_order' => 1, 'type' => 'text',
        ]);

        Sanctum::actingAs($admin);
        // Attempt to move course B's lesson into course A's tree via course A's
        // reorder endpoint, referencing course B's section id.
        $this->postJson("/api/courses/{$courseA->id}/reorder", [
            'lessons' => [
                ['id' => $lessonB->id, 'section_id' => $sectionB->id, 'sort_order' => 1],
            ],
        ])->assertNotFound();

        // Also rejected when the lesson id itself is foreign but section is own.
        $this->postJson("/api/courses/{$courseA->id}/reorder", [
            'lessons' => [
                ['id' => $lessonB->id, 'section_id' => $sectionA->id, 'sort_order' => 1],
            ],
        ])->assertOk();
        // The scoped update must not have touched the foreign lesson.
        $this->assertSame($sectionB->id, $lessonB->fresh()->section_id);
        $this->assertSame($courseB->id, $lessonB->fresh()->course_id);
    }

    public function test_forgot_password_does_not_enumerate_accounts(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJson([
                'message' => 'If an account exists for this email, a password reset link has been sent.',
            ]);
    }
}
