<?php

namespace Tests\Feature;

use App\Jobs\SendOtpEmailJob;
use App\Mail\StudentLoginOtpMail;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudentLoginOtp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackendCachingAndAsyncQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function createCourse(array $attributes = []): Course
    {
        return Course::create(array_merge([
            'title' => 'Test Course ' . uniqid(),
            'slug' => 'test-course-' . uniqid(),
            'description' => 'Test description for course',
            'category' => 'Technology',
            'instructor' => 'Test Instructor',
            'difficulty' => 'Beginner',
            'duration' => '8 Weeks',
            'price' => 9999,
            'is_published' => true,
        ], $attributes));
    }

    /**
     * 1. Public catalog queries are cached for 60 seconds.
     */
    public function test_public_catalog_is_cached_with_60_second_ttl(): void
    {
        $course = $this->createCourse([
            'title' => 'Initial Course Title',
            'is_published' => true,
        ]);

        // First request populates cache
        $response1 = $this->getJson('/api/courses');
        $response1->assertOk()
            ->assertJsonFragment(['title' => 'Initial Course Title']);

        // Directly modify database without triggering invalidation to test cache serving
        Course::where('id', $course->id)->update(['title' => 'Direct DB Modified Title']);

        // Second request should serve cached version
        $response2 = $this->getJson('/api/courses');
        $response2->assertOk()
            ->assertJsonFragment(['title' => 'Initial Course Title']);
    }

    /**
     * 2. Cache keys separate by query parameters (search, category, difficulty).
     */
    public function test_public_catalog_cache_keys_are_separated_by_filter_parameters(): void
    {
        $this->createCourse([
            'title' => 'React Fundamentals',
            'category' => 'Frontend',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        $this->createCourse([
            'title' => 'Advanced Kubernetes',
            'category' => 'DevOps',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        // Search for React
        $reactRes = $this->getJson('/api/courses?search=React');
        $reactRes->assertOk()
            ->assertJsonFragment(['title' => 'React Fundamentals'])
            ->assertJsonMissing(['title' => 'Advanced Kubernetes']);

        // Search for Kubernetes
        $k8sRes = $this->getJson('/api/courses?search=Kubernetes');
        $k8sRes->assertOk()
            ->assertJsonFragment(['title' => 'Advanced Kubernetes'])
            ->assertJsonMissing(['title' => 'React Fundamentals']);
    }

    /**
     * 3. Authenticated student requests bypass cache to compute personalized enrollment data.
     */
    public function test_authenticated_student_requests_bypass_public_cache(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['is_published' => true]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 45,
            'enrolled_at' => now(),
        ]);

        // Public visitor does not have student enrollment
        $publicRes = $this->getJson('/api/courses');
        $publicRes->assertOk()
            ->assertJsonMissing(['is_enrolled' => true]);

        // Authenticated student gets is_enrolled = true
        $authRes = $this->actingAs($student)->getJson('/api/courses');
        $authRes->assertOk()
            ->assertJsonFragment(['is_enrolled' => true]);
    }

    /**
     * 4. Course creation/update/deletion invalidates the public catalog cache.
     */
    public function test_course_mutations_invalidate_public_catalog_cache(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse([
            'title' => 'Original Title',
            'is_published' => true,
        ]);

        // Warm cache
        $this->getJson('/api/courses')->assertJsonFragment(['title' => 'Original Title']);

        // Admin updates course via API
        $this->actingAs($admin)->putJson("/api/courses/{$course->id}", [
            'title' => 'Updated By Admin Title',
            'is_published' => true,
        ])->assertOk();

        // Next public request receives the updated data immediately
        $this->getJson('/api/courses')->assertJsonFragment(['title' => 'Updated By Admin Title']);
    }

    /**
     * 5. OTP generation dispatches SendOtpEmailJob asynchronously to the queue.
     */
    public function test_otp_generation_dispatches_async_queue_job(): void
    {
        Queue::fake();

        $student = User::factory()->create(['email' => 'student.queue@masterintech.test']);
        $otpService = app(OtpService::class);

        $payload = $otpService->createOtpForUser($student);

        $this->assertNotEmpty($payload['temp_token']);
        $this->assertEquals(30, $payload['expires_in']);

        Queue::assertPushed(SendOtpEmailJob::class, function (SendOtpEmailJob $job) use ($student) {
            return $job->userId === $student->id && strlen($job->otp) === 6 && $job->expirySeconds === 30;
        });
    }

    /**
     * 6. SendOtpEmailJob executes and sends the Mailable safely when OTP is active.
     */
    public function test_send_otp_email_job_executes_mail_when_otp_is_active(): void
    {
        Mail::fake();

        $student = User::factory()->create(['email' => 'student.job@masterintech.test']);

        // Synchronously store OTP in database
        $rawOtp = '654321';
        StudentLoginOtp::create([
            'user_id' => $student->id,
            'temp_token_hash' => hash('sha256', 'sample_temp_token'),
            'otp_hash' => Hash::make($rawOtp),
            'attempts' => 0,
            'max_attempts' => 3,
            'expires_at' => now()->addSeconds(30),
            'resend_available_at' => now()->addSeconds(30),
        ]);

        $job = new SendOtpEmailJob($student->id, $rawOtp, 30);
        $job->handle();

        Mail::assertSent(StudentLoginOtpMail::class, function (StudentLoginOtpMail $mail) use ($student, $rawOtp) {
            return $mail->hasTo($student->email) && $mail->otp === $rawOtp && $mail->expirySeconds === 30;
        });
    }

    /**
     * 7. SendOtpEmailJob discards stale/expired OTP emails safely.
     */
    public function test_send_otp_email_job_suppresses_expired_otps(): void
    {
        Mail::fake();

        $student = User::factory()->create(['email' => 'student.stale@masterintech.test']);

        // Create an expired OTP (expired 10 seconds ago)
        StudentLoginOtp::create([
            'user_id' => $student->id,
            'temp_token_hash' => hash('sha256', 'expired_token'),
            'otp_hash' => Hash::make('112233'),
            'attempts' => 0,
            'max_attempts' => 3,
            'expires_at' => now()->subSeconds(10),
            'resend_available_at' => now()->subSeconds(10),
        ]);

        $job = new SendOtpEmailJob($student->id, '112233', 30);
        $job->handle();

        // Stale OTP must NOT be sent
        Mail::assertNotSent(StudentLoginOtpMail::class);
    }
}
