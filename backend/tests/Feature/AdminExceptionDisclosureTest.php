<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the admin exception-disclosure remediation.
 *
 * Each test forces an unexpected transactional fault inside the try block
 * (via a CourseEnrollment creating listener that throws a realistic
 * QueryException-style message) and proves the client receives only the
 * fixed generic 500 message — never SQLSTATE, SQL, table/key names, paths,
 * or raw exception text.
 */
class AdminExceptionDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failEnrollmentCreate = false;

    private static string $faultMessage = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '9-9' for key 'course_enrollments_user_id_course_id_unique' (SQL: insert into `course_enrollments` (`user_id`, `course_id`) values (9, 9)) in /var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:123";

    protected function setUp(): void
    {
        parent::setUp();

        // Register on every setUp: the application (and its model event
        // dispatcher) is rebuilt for each test, so a one-time registration
        // would be lost after the first test.
        CourseEnrollment::creating(function () {
            if (self::$failEnrollmentCreate) {
                throw new \Exception(self::$faultMessage);
            }
        });
    }

    protected function tearDown(): void
    {
        self::$failEnrollmentCreate = false;

        parent::tearDown();
    }

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => 'DISC',
            'description' => 'Comprehensive technical training course description.',
            'category' => 'Engineering',
            'instructor' => 'Senior Specialist',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ], $attributes));
    }

    private function assertSanitized500(string $content, string $faultMessage): void
    {
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('Duplicate entry', $content);
        $this->assertStringNotContainsString('course_enrollments', $content);
        $this->assertStringNotContainsString('for key', $content);
        $this->assertStringNotContainsString('SQL:', $content);
        $this->assertStringNotContainsString('Connection.php', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString($faultMessage, $content);
    }

    public function test_admin_enrollment_store_hides_transaction_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();

        Sanctum::actingAs($admin);
        Log::spy();
        self::$failEnrollmentCreate = true;

        try {
            $response = $this->postJson('/api/admin/enrollments', [
                'user_id' => $student->id,
                'course_id' => $course->id,
            ]);
        } finally {
            self::$failEnrollmentCreate = false;
        }

        $response->assertStatus(500)
            ->assertJson(['message' => 'Failed to create enrollment. Please try again.']);
        $this->assertSanitized500($response->getContent(), self::$faultMessage);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_crm_convert_hides_transaction_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();
        $lead = Enquiry::create([
            'name' => 'Disclosure Probe',
            'email' => 'disclosure.probe@example.com',
            'phone' => '+91 9000000001',
            'course_id' => $course->id,
            'status' => Enquiry::STATUS_NEW,
        ]);

        Sanctum::actingAs($admin);
        Log::spy();
        self::$failEnrollmentCreate = true;

        try {
            $response = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
                'course_id' => $course->id,
            ]);
        } finally {
            self::$failEnrollmentCreate = false;
        }

        $response->assertStatus(500)
            ->assertJson(['message' => 'Failed to convert lead to student. Please try again.']);
        $this->assertSanitized500($response->getContent(), self::$faultMessage);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_enquiry_enroll_hides_transaction_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();
        $enquiry = Enquiry::create([
            'name' => 'Disclosure Enrollee',
            'email' => 'disclosure.enrollee@example.com',
            'phone' => '+91 9000000002',
            'course_id' => $course->id,
            'status' => Enquiry::STATUS_NEW,
        ]);

        Sanctum::actingAs($admin);
        Log::spy();
        self::$failEnrollmentCreate = true;

        try {
            $response = $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
                'course_id' => $course->id,
            ]);
        } finally {
            self::$failEnrollmentCreate = false;
        }

        $response->assertStatus(500)
            ->assertJson(['message' => 'Failed to enroll student. Please try again.']);
        $this->assertSanitized500($response->getContent(), self::$faultMessage);
        Log::shouldHaveReceived('error')->once();
    }
}
