<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnquiryLeadAndCatalogOptionTest extends TestCase
{
    use RefreshDatabase;

    private function createPublishedAiCourse(): Course
    {
        return Course::create([
            'title' => 'Artificial Intelligence',
            'slug' => 'artificial-intelligence',
            'code' => 'AI',
            'description' => 'Foundations and applications of Artificial Intelligence.',
            'category' => 'AI & ML',
            'instructor' => 'Faculty Lead',
            'duration' => '12 Weeks',
            'difficulty' => 'Beginner to Advanced',
            'is_published' => true,
            'status' => 'published',
        ]);
    }

    public function test_artificial_intelligence_appears_as_a_valid_public_enquiry_option(): void
    {
        $this->createPublishedAiCourse();

        $res = $this->getJson('/api/courses');
        $res->assertOk();

        $titles = collect($res->json())->pluck('title');
        $this->assertTrue(
            $titles->contains('Artificial Intelligence'),
            'Artificial Intelligence must be selectable from the existing public course catalog.'
        );
    }

    public function test_public_enquiry_for_artificial_intelligence_creates_existing_enquiry_record(): void
    {
        $course = $this->createPublishedAiCourse();
        $userCountBefore = User::count();
        $enrollmentCountBefore = CourseEnrollment::count();

        $res = $this->postJson('/api/enquiries', [
            'name' => 'Priya Nair',
            'email' => 'priya.nair@example.com',
            'phone' => '9876500123',
            'course_id' => $course->id,
            'course_title' => 'Artificial Intelligence',
            'message' => 'Course admission enquiry',
        ]);

        $res->assertCreated()
            ->assertJsonPath('enquiry.name', 'Priya Nair')
            ->assertJsonPath('enquiry.email', 'priya.nair@example.com')
            ->assertJsonPath('enquiry.phone', '9876500123')
            ->assertJsonPath('enquiry.course_id', $course->id)
            ->assertJsonPath('enquiry.course_title', 'Artificial Intelligence')
            ->assertJsonPath('enquiry.status', 'new');

        $this->assertDatabaseHas('enquiries', [
            'email' => 'priya.nair@example.com',
            'course_id' => $course->id,
            'course_title' => 'Artificial Intelligence',
            'status' => Enquiry::STATUS_NEW,
            'user_id' => null,
            'enrolled_user_id' => null,
        ]);

        $this->assertEquals($userCountBefore, User::count());
        $this->assertEquals($enrollmentCountBefore, CourseEnrollment::count());
        $this->assertDatabaseMissing('users', ['email' => 'priya.nair@example.com']);
        $this->assertEquals(0, CourseEnrollment::where('course_id', $course->id)->count());
    }

    public function test_public_enquiry_by_course_title_maps_to_existing_catalog_course(): void
    {
        $course = $this->createPublishedAiCourse();

        $res = $this->postJson('/api/enquiries', [
            'name' => 'Rahul Mehta',
            'email' => 'rahul.mehta@example.com',
            'phone' => '9899320575',
            'course_title' => 'Artificial Intelligence',
            'message' => 'Requesting a live demo.',
        ]);

        $res->assertCreated()
            ->assertJsonPath('enquiry.course_id', $course->id)
            ->assertJsonPath('enquiry.course_title', 'Artificial Intelligence');

        $this->assertDatabaseHas('enquiries', [
            'email' => 'rahul.mehta@example.com',
            'course_id' => $course->id,
            'course_title' => 'Artificial Intelligence',
        ]);
    }

    public function test_admin_can_retrieve_submitted_public_enquiry_in_cpanel_list(): void
    {
        $course = $this->createPublishedAiCourse();

        $this->postJson('/api/enquiries', [
            'name' => 'Anita Sharma',
            'email' => 'anita.sharma@example.com',
            'phone' => '9123456780',
            'course_id' => $course->id,
            'course_title' => 'Artificial Intelligence',
            'message' => 'Interested after counselling call.',
        ])->assertCreated();

        $admin = User::factory()->create(['role' => 'admin']);
        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/enquiries');

        $list->assertOk();
        $lead = collect($list->json())->firstWhere('email', 'anita.sharma@example.com');
        $this->assertNotNull($lead, 'Submitted public enquiry must appear in Admin C-Panel enquiry list.');
        $this->assertEquals('Anita Sharma', $lead['name']);
        $this->assertEquals('9123456780', $lead['phone']);
        $this->assertEquals('Artificial Intelligence', $lead['course_title']);
        $this->assertEquals('new', $lead['status']);
        $this->assertNotEmpty($lead['created_at']);

        $filtered = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/enquiries?course_id=' . $course->id);
        $filtered->assertOk();
        $this->assertTrue(
            collect($filtered->json())->contains(fn ($row) => $row['email'] === 'anita.sharma@example.com')
        );
    }
}
