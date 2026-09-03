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
        $course = Course::where('id', $courseId)->orWhere('slug', $courseId)->first();

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
        $course = Course::where('id', $courseId)->orWhere('slug', $courseId)->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        // Verify user is enrolled
        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();

        if (! $isEnrolled && $user->role !== 'admin') {
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
