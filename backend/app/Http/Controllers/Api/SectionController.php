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
        $isManager = $user && ($user->isAdmin() || $courseModel->instructor_id === $user->id);

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

        \App\Models\AuditLog::log('created_section', $section, null, $section->toArray());

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

        $old = $section->toArray();
        $section->update($validated);

        \App\Models\AuditLog::log('updated_section', $section, $old, $section->fresh()->toArray());

        return response()->json($section->fresh()->load('lessons'));
    }

    /**
     * Toggle publish state of a section.
     */
    public function togglePublish(Request $request, Course $course, Section $section)
    {
        $this->authorizeCourseAccess($request, $course);
        $this->authorizeSection($course, $section);

        $wasPublished = (bool) $section->is_published;
        $section->update([
            'is_published' => ! $section->is_published,
        ]);

        \App\Models\AuditLog::log('toggled_section_publish', $section, ['is_published' => $wasPublished], ['is_published' => $section->is_published]);

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

        $old = $section->toArray();
        $section->delete();

        \App\Models\AuditLog::log('deleted_section', null, $old, null);

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
                // Collect the course's own section ids once: a reorder must never
                // move a lesson into a section belonging to another course.
                $ownSectionIds = $course->sections()->pluck('id')->all();
                foreach ($validated['lessons'] as $lData) {
                    $update = ['sort_order' => $lData['sort_order']];
                    if (! empty($lData['section_id'])) {
                        if (! in_array($lData['section_id'], $ownSectionIds, true)) {
                            abort(404, 'Section not found for this course.');
                        }
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

        \App\Models\AuditLog::log('reordered_curriculum', $course, null, [
            'sections' => collect($validated['sections'] ?? [])->pluck('sort_order', 'id')->all(),
            'lessons' => collect($validated['lessons'] ?? [])->pluck('sort_order', 'id')->all(),
        ]);

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

        if (! $user->isAdmin() && $course->instructor_id !== $user->id) {
            abort(403, 'Unauthorized. You can only manage curriculum for your own courses.');
        }
    }
}
