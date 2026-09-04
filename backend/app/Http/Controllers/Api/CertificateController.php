<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    /**
     * Generate or fetch certificate of completion for the authenticated student.
     */
    public function generate(Request $request, $courseId)
    {
        $user = $request->user();
        $course = Course::where('id', $courseId)->orWhere('slug', $courseId)->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        // Check if certificate already generated
        $existing = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->with(['course', 'user:id,name,email'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Certificate already issued.',
                'certificate' => $existing,
            ]);
        }

        // Verify enrollment and completion inside a transaction with a lock so
        // concurrent requests cannot mint duplicate certificates (M2).
        return DB::transaction(function () use ($user, $course) {
            $enrollment = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (! $enrollment) {
                return response()->json(['message' => 'You must be enrolled in this course.'], 403);
            }

            // Re-check under lock in case another request already issued one.
            $existing = Certificate::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Certificate already issued.',
                    'certificate' => $existing->load(['course', 'user:id,name,email']),
                ]);
            }

            $totalLessons = Lesson::where('course_id', $course->id)->where('is_published', true)->count();
            $completedLessons = LessonProgress::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('completed', true)
                ->count();

            // HIGH-1: A course with no published lessons has nothing to complete.
            // Reject the request instead of skipping the completion check and
            // minting a certificate for an empty course.
            if ($totalLessons === 0) {
                return response()->json([
                    'message' => 'This course has no lessons available to complete. Certificate cannot be issued.',
                    'completed' => 0,
                    'total' => 0,
                ], 422);
            }

            // HIGH-1: require every published lesson to be completed.
            if ($completedLessons < $totalLessons) {
                return response()->json([
                    'message' => 'Please complete all lessons in the course to unlock your certificate.',
                    'completed' => $completedLessons,
                    'total' => $totalLessons,
                ], 422);
            }

            // M1: cryptographically unpredictable, collision-checked code.
            $code = $this->uniqueCertificateCode();

            $certificate = Certificate::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'certificate_code' => $code,
                'issued_at' => now(),
            ]);

            $enrollment->update([
                'status' => 'completed',
                'progress_percentage' => 100,
            ]);

            return response()->json([
                'message' => 'Congratulations! Certificate generated successfully.',
                'certificate' => $certificate->load(['course', 'user:id,name,email']),
            ], 201);
        });
    }

    /**
     * Generate a unique, cryptographically unpredictable certificate code.
     */
    private function uniqueCertificateCode(): string
    {
        do {
            $code = 'MIT-' . now()->year . '-' . strtoupper(Str::random(12));
        } while (Certificate::where('certificate_code', $code)->exists());

        return $code;
    }

    /**
     * Get certificate details by code (authenticated user or public).
     */
    public function show($code)
    {
        $certificate = Certificate::where('certificate_code', $code)
            ->with(['course', 'user:id,name,email'])
            ->first();

        if (! $certificate) {
            return response()->json(['message' => 'Certificate not found'], 404);
        }

        return response()->json($certificate);
    }

    /**
     * Public verification of certificate authenticity.
     */
    public function verify($code)
    {
        $certificate = Certificate::where('certificate_code', $code)
            ->with(['course', 'user:id,name'])
            ->first();

        if (! $certificate) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid or unrecognized certificate ID.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'certificate_code' => $certificate->certificate_code,
            'recipient_name' => $certificate->user?->name,
            'course_title' => $certificate->course?->title,
            'instructor' => $certificate->course?->instructor,
            'issued_at' => $certificate->issued_at,
        ]);
    }
}
