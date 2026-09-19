<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseReview;
use Illuminate\Http\Request;

class CourseReviewController extends Controller
{
    /**
     * Get reviews and average rating for a course.
     */
    public function index($courseId)
    {
        // PG-safe id-or-slug (see CourseController::show): never compare a
        // non-numeric slug against the bigint id column (SQLSTATE 22P02).
        $course = Course::where(function ($q) use ($courseId) {
            if (is_numeric($courseId)) {
                $q->where('id', (int) $courseId)->orWhere('slug', $courseId);
            } else {
                $q->where('slug', $courseId);
            }
        })->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $reviews = CourseReview::where('course_id', $course->id)
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        $avgRating = $reviews->count() > 0 ? round($reviews->avg('rating'), 1) : 5.0;

        return response()->json([
            'average_rating' => $avgRating,
            'review_count' => $reviews->count(),
            'reviews' => $reviews,
        ]);
    }

    /**
     * Submit or update a review for a course (enrolled students only).
     */
    public function store(Request $request, $courseId)
    {
        $user = $request->user();
        // PG-safe id-or-slug (see CourseController::show).
        $course = Course::where(function ($q) use ($courseId) {
            if (is_numeric($courseId)) {
                $q->where('id', (int) $courseId)->orWhere('slug', $courseId);
            } else {
                $q->where('slug', $courseId);
            }
        })->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        // Verify user is enrolled (B3: only active/completed grant access).
        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        if (! $isEnrolled && ! $user->isAdmin()) {
            return response()->json([
                'message' => 'You must be enrolled in the course to write a review.',
            ], 403);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
        ]);

        $review = CourseReview::updateOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'rating' => $validated['rating'],
                'review_text' => $validated['review_text'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review' => $review->load('user:id,name'),
        ], 201);
    }
}
