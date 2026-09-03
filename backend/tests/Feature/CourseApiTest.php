<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class CourseApiTest extends TestCase
{
    public function test_courses_api_returns_complete_course_data_without_public_price(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson('/api/courses');

        $response->assertOk();
        $response->assertJsonStructure([
            '*' => [
                'id',
                'title',
                'description',
                'instructor',
                'duration',
                'difficulty',
            ],
        ]);

        if (! empty($response->json())) {
            $this->assertArrayNotHasKey('price', $response->json()[0]);
        }
    }

    public function test_admin_receives_internal_pricing(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->getJson('/api/courses');

        $response->assertOk();
        if (! empty($response->json())) {
            $this->assertArrayHasKey('price', $response->json()[0]);
            $this->assertArrayHasKey('internal_price', $response->json()[0]);
        }
    }
}
