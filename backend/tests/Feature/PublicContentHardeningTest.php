<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Certificate;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public-content boundaries (published/draft/archived), honest catalog
 * metrics, super_admin certificate parity, video-stream throttling wiring,
 * and LiveKit webhook audit logging.
 */
class PublicContentHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(array $overrides = []): Course
    {
        $title = 'Content ' . Str::random(6);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'Content fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '2 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'status' => 'published',
            'brochure' => 'https://cdn.example.com/brochure.pdf',
        ], $overrides));
    }

    public function test_draft_brochure_never_leaks(): void
    {
        $draft = $this->makeCourse(['is_published' => false, 'status' => 'draft']);

        $this->getJson("/api/courses/{$draft->id}/brochure")->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->getJson("/api/courses/{$draft->id}/brochure")->assertNotFound();
    }

    public function test_archived_course_hidden_from_public_but_managed_by_admin(): void
    {
        $course = $this->makeCourse();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/courses/{$course->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('status', 'archived');
        $this->assertFalse((bool) $course->fresh()->is_published);

        // Anonymous public catalog + detail + brochure + curriculum all hide it.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/courses')->assertOk()->assertJsonMissing(['id' => $course->id]);
        $this->getJson("/api/courses/{$course->id}")->assertNotFound();
        $this->getJson("/api/courses/{$course->id}/brochure")->assertNotFound();
        $this->getJson("/api/courses/{$course->id}/sections")->assertNotFound();

        // Students (even enrolled) cannot see it; admins can.
        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        Sanctum::actingAs($student);
        $this->getJson("/api/courses/{$course->id}")->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson("/api/courses/{$course->id}")->assertOk();
    }

    public function test_catalog_metrics_are_honest_without_activity(): void
    {
        $course = $this->makeCourse();

        $row = collect($this->getJson('/api/courses')->assertOk()->json())
            ->firstWhere('id', $course->id);

        $this->assertNull($row['average_rating']);
        $this->assertSame(0, (int) $row['reviews_count']);
        $this->assertSame(0, (int) $row['students_count']);
    }

    public function test_certificate_download_super_admin_parity(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $super = User::factory()->create(['role' => 'super_admin']);
        $course = $this->makeCourse();

        $cert = Certificate::create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-SUPERADMIN01',
            'issued_at' => now(),
        ]);

        $this->actingAs($super, 'sanctum')
            ->get("/api/student/certificates/{$cert->certificate_code}/download")
            ->assertOk();
    }

    public function test_video_stream_routes_are_throttled(): void
    {
        $this->assertNotNull(
            \Illuminate\Support\Facades\RateLimiter::limiter('video-stream'),
            'video-stream rate limiter must be registered.'
        );

        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->uri(), 'api/video-stream/'));

        $this->assertGreaterThan(0, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'throttle:video-stream',
                $route->gatherMiddleware(),
                "Route {$route->uri()} must carry the video-stream throttle."
            );
        }
    }

    public function test_livekit_room_lifecycle_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $course = $this->makeCourse();
        $session = ClassSession::create([
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'title' => 'Audit Session',
            'platform' => 'livekit',
            'livekit_room_name' => 'masterintech-session-4242',
            'livekit_status' => 'idle',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $admin->id,
        ]);

        $post = function (string $event) {
            // Genuine-shaped webhook delivery: claim-shaped JWT (iss/iat/nbf/
            // exp + sha256 of the exact raw body) alongside the event JSON.
            $rawBody = (string) json_encode(['event' => $event, 'room' => ['name' => 'masterintech-session-4242']]);
            $now = time();
            $claims = [
                'iss' => 'devkey',
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + 300,
                'sha256' => base64_encode(hash('sha256', $rawBody, true)),
            ];
            $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'devkey']);
            $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $input = $b64($header) . '.' . $b64((string) json_encode($claims));
            $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $input, 'secret', true)), '+/', '-_'), '=');

            $server = $this->transformHeadersToServerVars([
                'Authorization' => 'Bearer ' . $input . '.' . $sig,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

            return $this->call('POST', '/api/livekit/webhook', [], [], [], $server, $rawBody);
        };

        $post('room_started')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'livekit_room_started']);

        $post('room_finished')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'livekit_room_finished']);
        $this->assertSame('completed', $session->fresh()->status);
    }
}
