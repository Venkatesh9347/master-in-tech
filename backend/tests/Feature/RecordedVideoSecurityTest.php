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
}
