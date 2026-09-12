<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SectionController extends Controller
{
    /**
     * Display a listing of sections for a course (curriculum view).
     */
    public function index(Request $request, $course)
    {
        $courseModel = $course instanceof Course ? $course : Course::where('id', $course)->orWhere('slug', $course)->firstOrFail();
        $user = $request->user();
        $isManager = $user && (in_array($user->role, ['admin', 'super_admin'], true) || (int) $courseModel->instructor_id === (int) $user->id);

        // SEC-001 parity: an unpublished or archived course exposes no
        // curriculum to non-managers (previously leaked published sections,
        // lessons, quizzes and resources for hidden courses).
        if (! $isManager && (! $courseModel->is_published || ($courseModel->status ?? null) === 'archived')) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $sectionsQuery = $courseModel->sections()->orderBy('sort_order');
        if (! $isManager) {
            $sectionsQuery->where('is_published', true);
        }

        $sections = $sectionsQuery->with(['lessons' => function ($query) use ($isManager) {
            if (! $isManager) {
                $query->where('is_published', true);
            }
            $query->with(['quiz.questions.options', 'assignment', 'resources'])
                ->orderBy('sort_order');
        }])->get();

        return response()->json($sections);
    }

    /**
     * Store a new section (admin or course instructor).
     */
    public function store(Request $request, Course $course)
    {
        $this->authorizeCourseAccess($request, $course);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'integer|min:0',
            'is_published' => 'boolean',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);
        $validated['is_published'] = $validated['is_published'] ?? true;

        if (! isset($validated['sort_order'])) {
            $maxSort = $course->sections()->max('sort_order') ?? 0;
            $validated['sort_order'] = $maxSort + 1;
        }

        $section = $course->sections()->create($validated);

        return response()->json($section->load('lessons'), 201);
    }

    /**
     * Update a section (admin or course instructor).
     */
    public function update(Request $request, Course $course, Section $section)
    {
        $this->authorizeCourseAccess($request, $course);
        $this->authorizeSection($course, $section);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'sort_order' => 'integer|min:0',
            'is_published' => 'boolean',
        ]);

        $section->update($validated);

        return response()->json($section->fresh()->load('lessons'));
    }

    /**
     * Toggle publish state of a section.
     */
    public function togglePublish(Request $request, Course $course, Section $section)
    {
        $this->authorizeCourseAccess($request, $course);
        $this->authorizeSection($course, $section);

        $section->update([
            'is_published' => ! $section->is_published,
        ]);

        return response()->json([
            'message' => $section->is_published ? 'Module published successfully.' : 'Module unpublished (draft).',
            'section' => $section->fresh()->load('lessons'),
        ]);
    }

    /**
     * Delete a section (admin or course instructor) with delete safety.
     */
    public function destroy(Request $request, Course $course, Section $section)
    {
        $this->authorizeCourseAccess($request, $course);
        $this->authorizeSection($course, $section);

        $lessonIds = $section->lessons()->pluck('id');

        if ($lessonIds->isNotEmpty()) {
            $hasProgress = \App\Models\LessonProgress::whereIn('lesson_id', $lessonIds)
                ->where(function ($q) {
                    $q->where('started', true)->orWhere('completed', true);
                })
                ->exists();

            $hasQuizAttempts = \App\Models\QuizAttempt::whereIn('lesson_id', $lessonIds)->exists();
            $hasSubmissions = \App\Models\AssignmentSubmission::whereIn('lesson_id', $lessonIds)->exists();

            if ($hasProgress || $hasQuizAttempts || $hasSubmissions) {
                return response()->json([
                    'message' => 'Cannot delete module because student learning progress or submissions exist. Please unpublish it instead to preserve learning history.',
                    'delete_blocked' => true,
                ], 422);
            }
        }

        $section->delete();

        return response()->json(['message' => 'Module deleted successfully.']);
    }

    /**
     * Reorder sections and lessons inside a course.
     */
    public function reorder(Request $request, Course $course)
    {
        $this->authorizeCourseAccess($request, $course);

        $validated = $request->validate([
            'sections' => 'nullable|array',
            'sections.*.id' => 'required|integer',
            'sections.*.sort_order' => 'required|integer',
            'lessons' => 'nullable|array',
            'lessons.*.id' => 'required|integer',
            'lessons.*.section_id' => 'nullable|integer',
            'lessons.*.sort_order' => 'required|integer',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($course, $validated) {
            if (! empty($validated['sections'])) {
                foreach ($validated['sections'] as $sData) {
                    $course->sections()->where('id', $sData['id'])->update([
                        'sort_order' => $sData['sort_order'],
                    ]);
                }
            }

            if (! empty($validated['lessons'])) {
                foreach ($validated['lessons'] as $lData) {
                    $update = ['sort_order' => $lData['sort_order']];
                    if (! empty($lData['section_id'])) {
                        $update['section_id'] = $lData['section_id'];
                    }
                    $course->lessons()->where('id', $lData['id'])->update($update);
                }
            }
        });

        $sections = $course->sections()
            ->with(['lessons' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'message' => 'Curriculum reordered successfully.',
            'sections' => $sections,
        ]);
    }

    /**
     * Verify the section belongs to the course.
     */
    private function authorizeSection(Course $course, Section $section): void
    {
        if ($section->course_id !== $course->id) {
            abort(404, 'Section not found for this course.');
        }
    }

    /**
     * Verify the user is authorized to manage this course.
     */
    private function authorizeCourseAccess(Request $request, Course $course): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->role !== 'admin' && $course->instructor_id !== $user->id) {
            abort(403, 'Unauthorized. You can only manage curriculum for your own courses.');
        }
    }
}
