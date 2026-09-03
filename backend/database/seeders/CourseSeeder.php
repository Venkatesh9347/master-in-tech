<?php

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $courses = [
            [
                'title' => 'Full Stack Web Development',
                'slug' => 'full-stack-web-development',
                'description' => 'Learn frontend, backend, databases, and APIs to build production-ready web applications.',
                'instructor' => 'Sarah Johnson',
                'price' => 24999,
                'duration' => '12 weeks',
                'difficulty' => 'Beginner',
            ],
            [
                'title' => 'Python with AI',
                'slug' => 'python-with-ai',
                'description' => 'Master Python programming and the foundations of artificial intelligence and ML workflows.',
                'instructor' => 'Aman Verma',
                'price' => 19999,
                'duration' => '10 weeks',
                'difficulty' => 'Intermediate',
            ],
            [
                'title' => 'SAP FICO',
                'slug' => 'sap-fico',
                'description' => 'Understand financial accounting, controlling, and enterprise reporting within SAP systems.',
                'instructor' => 'Neha Patel',
                'price' => 17999,
                'duration' => '8 weeks',
                'difficulty' => 'Advanced',
            ],
        ];

        foreach ($courses as $course) {
            Course::firstOrCreate(
                ['slug' => $course['slug']],
                $course
            );
        }
    }
}
