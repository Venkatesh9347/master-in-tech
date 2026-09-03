<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\LiveClass;
use App\Models\User;
use Illuminate\Database\Seeder;

class LiveClassSeeder extends Seeder
{
    public function run(): void
    {
        $courses = Course::all();
        $tutor = User::where('role', 'tutor')->first() ?? User::first();

        if (! $tutor || $courses->isEmpty()) {
            return;
        }

        foreach ($courses as $course) {
            // 1. Live Now session
            LiveClass::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'title' => "Live Masterclass & Interactive Lab: {$course->title}",
                ],
                [
                    'instructor_id' => $course->instructor_id ?: $tutor->id,
                    'description' => "Real-time hands-on coding walkthrough, architecture review, and live Q&A for {$course->title}.",
                    'class_date' => now()->toDateString(),
                    'start_time' => '10:00',
                    'end_time' => '11:30',
                    'duration_minutes' => 90,
                    'status' => 'live',
                    'provider' => 'custom',
                    'meeting_id' => 'mit-live-' . $course->id,
                    'meeting_url' => 'https://meet.jit.si/mit-masterclass-' . $course->slug,
                    'host_url' => 'https://meet.jit.si/mit-masterclass-' . $course->slug,
                    'passcode' => env('SEED_LIVE_CLASS_PASSCODE', 'MIT2026'),
                    'is_chat_enabled' => true,
                    'is_mic_allowed_by_default' => false,
                    'started_at' => now()->subMinutes(15),
                ]
            );

            // 2. Upcoming Scheduled session
            LiveClass::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'title' => "Capstone Project Review & Code Teardown",
                ],
                [
                    'instructor_id' => $course->instructor_id ?: $tutor->id,
                    'description' => "Deep dive into production deployment strategies, code reviews, and industry best practices.",
                    'class_date' => now()->addDays(2)->toDateString(),
                    'start_time' => '14:00',
                    'end_time' => '15:30',
                    'duration_minutes' => 90,
                    'status' => 'scheduled',
                    'provider' => 'zoom',
                    'meeting_id' => '8429103847',
                    'meeting_url' => 'https://zoom.us/j/8429103847',
                    'passcode' => env('SEED_LIVE_CLASS_PASSCODE', 'MIT2026'),
                    'is_chat_enabled' => true,
                    'is_mic_allowed_by_default' => false,
                ]
            );

            // 3. Completed Archive session
            LiveClass::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'title' => "Orientation & Core Foundations Masterclass",
                ],
                [
                    'instructor_id' => $course->instructor_id ?: $tutor->id,
                    'description' => "Orientation session covering curriculum roadmaps, tooling setup, and study guides.",
                    'class_date' => now()->subDays(3)->toDateString(),
                    'start_time' => '11:00',
                    'end_time' => '12:30',
                    'duration_minutes' => 90,
                    'status' => 'completed',
                    'provider' => 'google_meet',
                    'meeting_id' => 'mit-orientation',
                    'meeting_url' => 'https://meet.google.com/mit-orientation',
                    'is_chat_enabled' => true,
                    'is_mic_allowed_by_default' => false,
                    'started_at' => now()->subDays(3)->setTime(11, 0),
                    'ended_at' => now()->subDays(3)->setTime(12, 30),
                ]
            );
        }
    }
}
