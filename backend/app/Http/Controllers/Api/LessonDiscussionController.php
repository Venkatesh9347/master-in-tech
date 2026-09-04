<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonDiscussion;
use App\Models\LessonDiscussionReply;
use Illuminate\Http\Request;

class LessonDiscussionController extends Controller
{
    /**
     * Get Q&A discussions and replies for a specific lesson.
     */
    public function index(Request $request, $courseId, $lessonId)
    {
        $course = Course::findOrFail($courseId);

        // L1: the lesson must belong to the specified course (no cross-course IDs)
        $this->assertLessonBelongsToCourse($courseId, $lessonId);

        $this->authorizeCourseAccess($request->user(), $course);

        $discussions = LessonDiscussion::where('course_id', $courseId)
            ->where('lesson_id', $lessonId)
            ->with(['user:id,name,role', 'replies.user:id,name,role'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($discussions);
    }

    /**
     * Post a new Q&A discussion question.
     */
    public function store(Request $request, $courseId, $lessonId)
    {
        $user = $request->user();

        $course = Course::findOrFail($courseId);

        // L1: the lesson must belong to the specified course (no cross-course IDs)
        $this->assertLessonBelongsToCourse($courseId, $lessonId);

        $this->authorizeCourseAccess($user, $course);

        $validated = $request->validate([
            'question' => 'required|string|min:3|max:2000',
        ]);

        $discussion = LessonDiscussion::create([
            'user_id' => $user->id,
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'question_text' => $validated['question'],
        ]);

        return response()->json(
            $discussion->load(['user:id,name,role', 'replies.user:id,name,role']),
            201
        );
    }

    /**
     * Post a reply to an existing discussion thread.
     */
    public function reply(Request $request, $discussionId)
    {
        $user = $request->user();

        $validated = $request->validate([
            'reply' => 'required|string|min:2|max:2000',
        ]);

        $discussion = LessonDiscussion::findOrFail($discussionId);

        $course = Course::findOrFail($discussion->course_id);
        $this->authorizeCourseAccess($request->user(), $course);

        $reply = LessonDiscussionReply::create([
            'discussion_id' => $discussion->id,
            'user_id' => $user->id,
            'reply_text' => $validated['reply'],
        ]);

        return response()->json(
            $reply->load('user:id,name,role'),
            201
        );
    }

    /**
     * Ensure the given lesson actually belongs to the specified course.
     */
    private function assertLessonBelongsToCourse($courseId, $lessonId): void
    {
        $exists = Lesson::where('id', $lessonId)
            ->where('course_id', $courseId)
            ->exists();

        if (! $exists) {
            abort(404, 'Lesson not found for this course.');
        }
    }

    /**
     * Ensure the authenticated user has access to the given course.
     */
    private function authorizeCourseAccess($user, Course $course): void
    {
        if ($user->role === 'admin') {
            return;
        }

        if ($user->role === 'tutor') {
            return;
        }

        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', '!=', 'dropped')
            ->exists();

        if (! $isEnrolled) {
            abort(403, 'Course Access Required. You must have an active enrollment in this course to participate in lesson discussions.');
        }
    }
}
