<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test Admin',
                'password' => env('SEED_DEFAULT_PASSWORD', 'password'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => env('SEED_DEFAULT_PASSWORD', 'password'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
   ['email' => 'tutor@example.com'],
            [
                'name' => 'Sarah Johnson (Tutor)',
                'password' => env('SEED_DEFAULT_PASSWORD', 'password'),
                'role' => 'tutor',
            ]
        );

        User::updateOrCreate(
            ['email' => 'student@example.com'],
            [
                'name' => 'Student Test',
                'password' => env('SEED_DEFAULT_PASSWORD', 'password'),
                'role' => 'student',
            ]
        );

        $this->call(CourseSeeder::class);
        $this->call(CourseCatalogSeeder::class);
        $this->call(LmsSeeder::class);
        $this->call(RichContentSeeder::class);
        $this->call(SecondaryDisciplinesContentSeeder::class);
        $this->call(PriorityCoursesSeeder::class);
        $this->call(CmsContentSeeder::class);
    }
}
