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
        // Never create known-password admin accounts in production. Seeders are
        // a development/test facility; running them against a live environment
        // would silently provision administrator credentials with a predictable
        // default password. Require an explicit opt-in before seeding in prod.
        if (app()->environment('production')) {
            $force = env('SEED_ALLOW_PRODUCTION', false);
            if (filter_var($force, FILTER_VALIDATE_BOOL) !== true) {
                throw new \RuntimeException(
                    'Seeding is disabled in the production environment. '.
                    'Set SEED_ALLOW_PRODUCTION=true only as a deliberate, one-time, '.
                    'controlled action if you truly need to seed this environment.'
                );
            }
        }

        $defaultPassword = env('SEED_DEFAULT_PASSWORD', 'password');

        $admin = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test Admin',
                'password' => $defaultPassword,
            ]
        );
        $admin->forceFill(['role' => 'admin'])->save();

        $admin2 = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => $defaultPassword,
            ]
        );
        $admin2->forceFill(['role' => 'admin'])->save();

        $tutor = User::updateOrCreate(
            ['email' => 'tutor@example.com'],
            [
                'name' => 'Sarah Johnson (Tutor)',
                'password' => $defaultPassword,
            ]
        );
        $tutor->forceFill(['role' => 'tutor'])->save();

        $student = User::updateOrCreate(
            ['email' => 'student@example.com'],
            [
                'name' => 'Student Test',
                'password' => $defaultPassword,
            ]
        );
        $student->forceFill(['role' => 'student'])->save();

        // CRM frontline staff: 4 telecallers + 2 course advisors.
        // Roles are scoped (own + unassigned leads) by Enquiry::scopeVisibleTo.
        foreach (range(1, 4) as $i) {
            $telecaller = User::updateOrCreate(
                ['email' => "telecaller{$i}@example.com"],
                [
                    'name' => "Telecaller {$i}",
                    'password' => $defaultPassword,
                ]
            );
            $telecaller->forceFill(['role' => 'telecaller'])->save();
        }

        foreach (range(1, 2) as $i) {
            $advisor = User::updateOrCreate(
                ['email' => "advisor{$i}@example.com"],
                [
                    'name' => "Course Advisor {$i}",
                    'password' => $defaultPassword,
                ]
            );
            $advisor->forceFill(['role' => 'course_advisor'])->save();
        }

        $this->call(CourseSeeder::class);
        $this->call(CourseCatalogSeeder::class);
        $this->call(LmsSeeder::class);
        $this->call(RichContentSeeder::class);
        $this->call(SecondaryDisciplinesContentSeeder::class);
        $this->call(PriorityCoursesSeeder::class);
        $this->call(CmsContentSeeder::class);
    }
}
