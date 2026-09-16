<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use App\Models\VideoAsset;
use App\Models\VideoPlaybackSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecordedVideoSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private User $enrolledStudent;
    private User $unEnrolledStudent;
    private Course $course;
    private Section $section;
    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->create([
            'name' => 'Dr. Video Engineer',
            'email' => 'instructor@example.com',
            'role' => 'tutor',
            'phone' => '+91 9123456780',
        ]);

        $this->course = Course::create([
            'title' => 'Advanced Cloud Microservices Masterclass',
            'slug' => 'advanced-cloud-microservices',
            'description' => 'Secure enterprise curriculum',
            'instructor' => 'Dr. Video Engineer',
            'instructor_id' => $this->instructor->id,
            'duration' => '10 Weeks',
            'difficulty' => 'Advanced',
            'price' => 299.00,
            'is_published' => true,
        ]);

        $this->section = Section::create([
            'course_id' => $this->course->id,
            'title' => 'Module 1: Architecture',
            'slug' => 'module-1-architecture',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $this->lesson = Lesson::create([
            'course_id' => $this->course->id,
            'section_id' => $this->section->id,
            'title' => 'Deep Dive: Distributed Caching',
            'slug' => 'deep-dive-distributed-caching',
            'type' => 'video',
            'duration' => '15 min',
            'is_published' => true,
            'metadata' => [
                'aspect_ratio' => '16:9',
            ],
        ]);

        $this->enrolledStudent = User::factory()->create([
            'name' => 'Alice Student',
            'email' => 'alice.secure@example.com',
            'role' => 'student',
            'phone' => '+91 9876543210',
        ]);

        CourseEnrollment::create([
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);

        $this->unEnrolledStudent = User::factory()->create([
            'name' => 'Bob Hacker',
            'email' => 'bob.hacker@example.com',
            'role' => 'student',
            'phone' => '+91 9999988888',
        ]);
    }

    public function test_enrolled_student_can_request_playback_authorization_and_receives_token_and_watermark_with_full_phone(): void
    {
        $response = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'lesson_id',
                'title',
                'session' => [
                    'asset_id',
                    'playback_url',
                    'playback_token',
                    'expires_in',
                    'duration_seconds',
                    'resolutions',
                    'watermark' => [
                        'mobile_number',
                        'user_id',
                        'session_id',
                    ],
                ],
            ])
            ->assertJson([
                'lesson_id' => $this->lesson->id,
                'session' => [
                    'watermark' => [
                        'mobile_number' => '+91 9876543210', // Full registered mobile number
                        'user_id' => $this->enrolledStudent->id,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('video_assets', [
            'lesson_id' => $this->lesson->id,
            'course_id' => $this->course->id,
        ]);

        $this->assertDatabaseHas('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'lesson_id' => $this->lesson->id,
        ]);
    }

    public function test_non_enrolled_student_cannot_request_playback_token(): void
    {
        $response = $this->actingAs($this->unEnrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $response->assertStatus(401);
    }

    public function test_student_can_fetch_master_playlist_and_decryption_key_with_valid_token(): void
    {
        // 1. Authorize
        $authRes = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $authRes->assertStatus(200);

        $assetId = $authRes->json('session.asset_id');
        $token = $authRes->json('session.playback_token');

        // 2. Fetch Master Playlist
        $masterRes = $this->get("/api/video-stream/{$assetId}/master.m3u8?token=" . urlencode($token));
        $masterRes->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')
            ->assertSee('#EXTM3U')
            ->assertSee('#EXT-X-STREAM-INF')
            ->assertSee('720p.m3u8');

        // 3. Fetch Variant Playlist (with AES-128 key tag)
        $variantRes = $this->get("/api/video-stream/{$assetId}/720p.m3u8?token=" . urlencode($token));
        $variantRes->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')
            ->assertSee('#EXT-X-KEY:METHOD=AES-128')
            ->assertSee('key?token=');

        // 4. Fetch 16-Byte AES-128 Binary Decryption Key
        $keyRes = $this->get("/api/video-stream/{$assetId}/key?token=" . urlencode($token));
        $keyRes->assertStatus(200)
            ->assertHeader('Content-Type', 'application/octet-stream');

        $this->assertEquals(16, strlen($keyRes->getContent()));
    }

    public function test_decryption_key_request_is_rejected_with_invalid_or_expired_token(): void
    {
        // 1. Authorize
        $authRes = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $assetId = $authRes->json('session.asset_id');
        $token = $authRes->json('session.playback_token');

        // 2. Tampered token
        $tamperedRes = $this->get("/api/video-stream/{$assetId}/key?token=" . urlencode($token . 'tamper'));
        $tamperedRes->assertStatus(403);

        // 3. Expired token (travel 10 minutes into the future)
        $this->travel(10)->minutes();
        $expiredRes = $this->get("/api/video-stream/{$assetId}/key?token=" . urlencode($token));
        $expiredRes->assertStatus(403);
    }

    public function test_unpublished_lesson_video_is_rejected_for_students(): void
    {
        $unpublishedLesson = Lesson::create([
            'course_id' => $this->course->id,
            'section_id' => $this->section->id,
            'title' => 'Secret Unpublished Video',
            'type' => 'video',
            'is_published' => false,
        ]);

        $response = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$unpublishedLesson->id}/playback-auth");

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // S-03 regression: logout / revocation / key authorization
    // -----------------------------------------------------------------

    public function test_logout_invalidates_user_playback_session(): void
    {
        $this->enrolledStudent->forceFill(['password' => Hash::make('password123')])->save();

        $login = $this->postJson('/api/login', [
            'email' => $this->enrolledStudent->email,
            'password' => 'password123',
        ]);
        $login->assertStatus(200);
        $apiToken = $login->json('access_token');
        $this->assertNotEmpty($apiToken);

        $auth = $this->withToken($apiToken)
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $playbackToken = $auth->json('session.playback_token');
        $this->assertNotEmpty($playbackToken);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($playbackToken))
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();

        $this->withToken($apiToken)->postJson('/api/logout')->assertStatus(200);

        $this->assertDatabaseMissing('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'token_hash' => hash('sha256', $playbackToken),
        ]);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($playbackToken))
            ->assertStatus(403);
        $this->get('/api/video-stream/' . $assetId . '/master.m3u8?token=' . urlencode($playbackToken))
            ->assertStatus(403);
    }

    public function test_logout_does_not_invalidate_other_users_session(): void
    {
        $studentB = User::factory()->create([
            'name' => 'Second Student',
            'email' => 'second.student@example.com',
            'role' => 'student',
            'phone' => '+91 9888877777',
            'password' => Hash::make('password123'),
        ]);
        CourseEnrollment::create([
            'user_id' => $studentB->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);
        $this->enrolledStudent->forceFill(['password' => Hash::make('password123')])->save();

        $loginA = $this->postJson('/api/login', [
            'email' => $this->enrolledStudent->email,
            'password' => 'password123',
        ]);
        $loginA->assertStatus(200);
        $tokenA = $loginA->json('access_token');

        $this->app['auth']->forgetGuards();

        $loginB = $this->postJson('/api/login', [
            'email' => $studentB->email,
            'password' => 'password123',
        ]);
        $loginB->assertStatus(200);
        $tokenB = $loginB->json('access_token');

        $this->app['auth']->forgetGuards();

        $authA = $this->withToken($tokenA)
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $authA->assertStatus(200);
        $playbackA = $authA->json('session.playback_token');
        $assetId = $authA->json('session.asset_id');

        $this->app['auth']->forgetGuards();

        $authB = $this->withToken($tokenB)
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $authB->assertStatus(200);
        $playbackB = $authB->json('session.playback_token');

        $this->app['auth']->forgetGuards();

        $this->withToken($tokenA)->postJson('/api/logout')->assertStatus(200);

        $this->assertDatabaseMissing('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'token_hash' => hash('sha256', $playbackA),
        ]);
        $this->assertDatabaseHas('video_playback_sessions', [
            'user_id' => $studentB->id,
            'token_hash' => hash('sha256', $playbackB),
        ]);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($playbackA))
            ->assertStatus(403);
        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($playbackB))
            ->assertStatus(200);
    }

    public function test_cancelling_enrollment_invalidates_playback(): void
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token))
            ->assertStatus(200);

        $admin = User::factory()->create(['role' => 'admin']);
        $enrollment = CourseEnrollment::where('user_id', $this->enrolledStudent->id)
            ->where('course_id', $this->course->id)
            ->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/enrollments/{$enrollment->id}", ['status' => 'cancelled'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
        ]);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token))
            ->assertStatus(403);
    }

    public function test_pending_enrollment_invalidates_playback(): void
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        $admin = User::factory()->create(['role' => 'admin']);
        $enrollment = CourseEnrollment::where('user_id', $this->enrolledStudent->id)
            ->where('course_id', $this->course->id)
            ->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/enrollments/{$enrollment->id}", ['status' => 'pending'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
        ]);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token))
            ->assertStatus(403);
    }

    public function test_destroying_enrollment_invalidates_playback(): void
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        $admin = User::factory()->create(['role' => 'admin']);
        $enrollment = CourseEnrollment::where('user_id', $this->enrolledStudent->id)
            ->where('course_id', $this->course->id)
            ->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/enrollments/{$enrollment->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
        ]);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token))
            ->assertStatus(403);
    }

    public function test_revoking_course_a_does_not_invalidate_course_b(): void
    {
        $courseB = Course::create([
            'title' => 'Second Course Isolation',
            'slug' => 'second-course-isolation',
            'description' => 'Isolation curriculum',
            'instructor' => 'Dr. Video Engineer',
            'instructor_id' => $this->instructor->id,
            'duration' => '6 Weeks',
            'difficulty' => 'Intermediate',
            'price' => 199.00,
            'is_published' => true,
        ]);
        $sectionB = Section::create([
            'course_id' => $courseB->id,
            'title' => 'Module B',
            'slug' => 'module-b',
            'sort_order' => 0,
            'is_published' => true,
        ]);
        $lessonB = Lesson::create([
            'course_id' => $courseB->id,
            'section_id' => $sectionB->id,
            'title' => 'Lesson B Video',
            'slug' => 'lesson-b-video',
            'type' => 'video',
            'duration' => '10 min',
            'is_published' => true,
        ]);
        CourseEnrollment::create([
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $courseB->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);

        $authA = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $authA->assertStatus(200);
        $assetA = $authA->json('session.asset_id');
        $tokenA = $authA->json('session.playback_token');

        $authB = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$courseB->id}/lessons/{$lessonB->id}/playback-auth");
        $authB->assertStatus(200);
        $assetB = $authB->json('session.asset_id');
        $tokenB = $authB->json('session.playback_token');

        $admin = User::factory()->create(['role' => 'admin']);
        $enrollmentA = CourseEnrollment::where('user_id', $this->enrolledStudent->id)
            ->where('course_id', $this->course->id)
            ->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/enrollments/{$enrollmentA->id}", ['status' => 'cancelled'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
        ]);
        $this->assertDatabaseHas('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $courseB->id,
        ]);

        $this->get('/api/video-stream/' . $assetA . '/key?token=' . urlencode($tokenA))
            ->assertStatus(403);
        $this->get('/api/video-stream/' . $assetB . '/key?token=' . urlencode($tokenB))
            ->assertStatus(200);
    }

    public function test_revoking_user_a_does_not_invalidate_user_b(): void
    {
        $studentB = User::factory()->create([
            'name' => 'Isolated Student B',
            'email' => 'isolated.b@example.com',
            'role' => 'student',
            'phone' => '+91 9777766666',
        ]);
        CourseEnrollment::create([
            'user_id' => $studentB->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);

        $authA = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $authA->assertStatus(200);
        $assetId = $authA->json('session.asset_id');
        $tokenA = $authA->json('session.playback_token');

        $authB = $this->actingAs($studentB, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $authB->assertStatus(200);
        $tokenB = $authB->json('session.playback_token');

        $admin = User::factory()->create(['role' => 'admin']);
        $enrollmentA = CourseEnrollment::where('user_id', $this->enrolledStudent->id)
            ->where('course_id', $this->course->id)
            ->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/enrollments/{$enrollmentA->id}", ['status' => 'cancelled'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('video_playback_sessions', [
            'user_id' => $this->enrolledStudent->id,
            'token_hash' => hash('sha256', $tokenA),
        ]);
        $this->assertDatabaseHas('video_playback_sessions', [
            'user_id' => $studentB->id,
            'token_hash' => hash('sha256', $tokenB),
        ]);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($tokenA))
            ->assertStatus(403);
        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($tokenB))
            ->assertStatus(200);
    }

    public function test_unexpired_token_cannot_obtain_key_after_revocation_even_with_session_row(): void
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        $admin = User::factory()->create(['role' => 'admin']);
        $enrollment = CourseEnrollment::where('user_id', $this->enrolledStudent->id)
            ->where('course_id', $this->course->id)
            ->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/enrollments/{$enrollment->id}", ['status' => 'cancelled'])
            ->assertStatus(200);

        // Defense-in-depth: even if a session row still exists (race/stale),
        // the key endpoint must refuse because enrollment is no longer active.
        $asset = VideoAsset::where('asset_id', $assetId)->firstOrFail();
        VideoPlaybackSession::create([
            'video_asset_id' => $asset->id,
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
            'lesson_id' => $this->lesson->id,
            'token_hash' => hash('sha256', $token),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'S-03 regression',
            'expires_at' => now()->addMinutes(5),
            'last_heartbeat_at' => now(),
        ]);

        $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token))
            ->assertStatus(403);
    }

    public function test_actively_enrolled_student_can_still_obtain_key(): void
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        $keyRes = $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token));
        $keyRes->assertStatus(200);
        $this->assertEquals(16, strlen($keyRes->getContent()));
    }

    public function test_valid_token_reuse_within_ttl_still_works(): void
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        // Repeated manifest + key reuse must remain permitted (no one-time-use).
        $this->get('/api/video-stream/' . $assetId . '/master.m3u8?token=' . urlencode($token))
            ->assertStatus(200);
        $this->get('/api/video-stream/' . $assetId . '/master.m3u8?token=' . urlencode($token))
            ->assertStatus(200);
        $this->get('/api/video-stream/' . $assetId . '/720p.m3u8?token=' . urlencode($token))
            ->assertStatus(200);
        $this->get('/api/video-stream/' . $assetId . '/720p.m3u8?token=' . urlencode($token))
            ->assertStatus(200);

        $first = $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token));
        $first->assertStatus(200);
        $second = $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token));
        $second->assertStatus(200);
        $this->assertEquals($first->getContent(), $second->getContent());
    }

    public function test_concurrent_multiple_hls_requests_remain_permitted(): void
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');
        $q = urlencode($token);

        $this->get("/api/video-stream/{$assetId}/master.m3u8?token={$q}")->assertStatus(200);
        $this->get("/api/video-stream/{$assetId}/720p.m3u8?token={$q}")->assertStatus(200);
        $this->get("/api/video-stream/{$assetId}/480p.m3u8?token={$q}")->assertStatus(200);
        $this->get("/api/video-stream/{$assetId}/360p.m3u8?token={$q}")->assertStatus(200);
        $this->get("/api/video-stream/{$assetId}/key?token={$q}")->assertStatus(200);
        $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.ts?token={$q}")
            ->assertStatus(200);
        $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.ts?token={$q}")
            ->assertStatus(200);
    }

    public function test_admin_playback_remains_valid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $auth = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        $this->get('/api/video-stream/' . $assetId . '/master.m3u8?token=' . urlencode($token))
            ->assertStatus(200);
        $keyRes = $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token));
        $keyRes->assertStatus(200);
        $this->assertEquals(16, strlen($keyRes->getContent()));
    }

    public function test_tutor_playback_remains_valid(): void
    {
        $auth = $this->actingAs($this->instructor, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);
        $assetId = $auth->json('session.asset_id');
        $token = $auth->json('session.playback_token');

        $this->get('/api/video-stream/' . $assetId . '/master.m3u8?token=' . urlencode($token))
            ->assertStatus(200);
        $keyRes = $this->get('/api/video-stream/' . $assetId . '/key?token=' . urlencode($token));
        $keyRes->assertStatus(200);
        $this->assertEquals(16, strlen($keyRes->getContent()));
    }

    // -----------------------------------------------------------------
    // S-04 regression: segment filename allowlist
    // -----------------------------------------------------------------

    private function authorizeValidSegmentToken(): array
    {
        $auth = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/lessons/{$this->lesson->id}/playback-auth");
        $auth->assertStatus(200);

        return [$auth->json('session.asset_id'), $auth->json('session.playback_token')];
    }

    public function test_legitimate_segment_succeeds_and_is_reusable(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        $first = $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.ts?token={$q}");
        $first->assertStatus(200);
        $second = $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.ts?token={$q}");
        $second->assertStatus(200);
        $this->assertEquals($first->getContent(), $second->getContent());
    }

    public function test_slash_traversal_rejected(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        // Raw ../ contains slashes: blocked at routing (404), never driver.
        $this->get("/api/video-stream/{$assetId}/segments/../nonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/..%2F..%2Fnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
    }

    public function test_backslash_traversal_rejected(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        // Raw backslash cannot be sent (Symfony rejects backslash URIs), so
        // cover the driver-reachable encoded forms (%5C -> backslash).
        $this->get("/api/video-stream/{$assetId}/segments/..%5Cnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/..%5C..%5Cnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/..%5c..%5cnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
    }

    public function test_encoded_and_double_encoded_traversal_rejected(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        $this->get("/api/video-stream/{$assetId}/segments/%2e%2e%2fnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/%2e%2e%5cnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/%252e%252e%252fnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/%252e%252e%255cnonexistent_xyz_123.ts?token={$q}")
            ->assertStatus(404);
    }

    public function test_wrong_extensions_rejected(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        $this->get("/api/video-stream/{$assetId}/segments/evil.php?token={$q}")->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/720p.m3u8?token={$q}")->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/enc.key?token={$q}")->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.mp4?token={$q}")->assertStatus(404);
    }

    public function test_absolute_windows_unc_paths_rejected(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        $this->get("/api/video-stream/{$assetId}/segments/%2Fetc%2Fpasswd?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/C:%5CWindows%5CSystem32%5Cevil_segment_000.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/C:%2FWindows%2FSystem32%2Fevil_segment_000.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/%5C%5Cserver%5Cshare%5Cevil_segment_000.ts?token={$q}")
            ->assertStatus(404);
    }

    public function test_null_byte_rejected_without_error_disclosure(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        $res = $this->get("/api/video-stream/{$assetId}/segments/720p_segment_000.ts%00?token={$q}");
        $res->assertStatus(404);
        $res->assertDontSee('Exception');
        $res->assertDontSee('storage');
        $res->assertDontSee('app/private');
    }

    public function test_cross_asset_materials_certificates_traversal_rejected(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        $otherAsset = 'vasset_00000000-0000-4000-8000-000000000000';
        $this->get("/api/video-stream/{$assetId}/segments/..%5C{$otherAsset}%5C720p_segment_000.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/..%5C{$otherAsset}%5Cenc.key?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/..%5Cmaterials%5Cprobe_segment_000.ts?token={$q}")
            ->assertStatus(404);
        $this->get("/api/video-stream/{$assetId}/segments/..%5Ccertificates%5Cprobe_segment_000.ts?token={$q}")
            ->assertStatus(404);
    }

    public function test_invalid_token_with_malicious_segment_remains_rejected(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();

        $bad = urlencode($token . 'tamper');
        $this->get("/api/video-stream/{$assetId}/segments/..%5Cnonexistent_xyz_123.ts?token={$bad}")
            ->assertStatus(403);
        $this->get("/api/video-stream/{$assetId}/segments/evil.php?token={$bad}")
            ->assertStatus(403);
    }

    public function test_valid_token_with_valid_segment_remains_200(): void
    {
        [$assetId, $token] = $this->authorizeValidSegmentToken();
        $q = urlencode($token);

        $this->get("/api/video-stream/{$assetId}/segments/480p_segment_001.ts?token={$q}")
            ->assertStatus(200);
        $this->get("/api/video-stream/{$assetId}/segments/360p_segment_002.ts?token={$q}")
            ->assertStatus(200);
    }
}
