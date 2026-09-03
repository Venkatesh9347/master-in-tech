<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TutorMaterialController extends Controller
{
    /**
     * List all materials for the tutor's assigned courses.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        $query = ClassMaterial::with([
            'course:id,title,category,instructor_id',
            'classSession:id,title,scheduled_date',
            'uploader:id,name,email',
        ]);

        if (! $isAdmin) {
            $assignedCourseIds = Course::where('instructor_id', $user->id)->pluck('id');
            $query->whereIn('course_id', $assignedCourseIds);
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('class_session_id')) {
            $query->where('class_session_id', $request->class_session_id);
        }

        $materials = $query->orderBy('created_at', 'desc')->get();

        return response()->json($materials);
    }

    /**
     * Upload a new learning material for an assigned course/class.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('upload_materials')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to upload materials.',
            ], 403);
        }

        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'class_session_id' => 'nullable|integer|exists:class_sessions,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'material_type' => 'nullable|string|max:50',
            'file' => 'required|file|max:25600|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,zip,png,jpg,jpeg',
        ]);

        $course = Course::findOrFail($validated['course_id']);

        // Verify tutor is assigned to this course
        if (! $isAdmin && $course->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. You cannot upload materials to a course not assigned to you.',
            ], 403);
        }

        // If class_session_id provided, verify it belongs to this course
        if (! empty($validated['class_session_id'])) {
            $session = ClassSession::findOrFail($validated['class_session_id']);
            if ($session->course_id !== $course->id) {
                return response()->json([
                    'message' => 'The selected class session does not belong to this course.',
                ], 422);
            }
        }

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $fileExtension = strtolower($file->getClientOriginalExtension());
        $path = $file->store('materials', 'public');
        $url = Storage::url($path);

        $material = ClassMaterial::create([
            'course_id' => $course->id,
            'class_session_id' => $validated['class_session_id'] ?? null,
            'uploaded_by' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'file_path' => $url,
            'file_name' => $fileName,
            'file_type' => $validated['material_type'] ?? $fileExtension,
            'file_size' => $fileSize,
        ]);

        // Audit Log
        AuditLog::log('uploaded_material', $material, null, [
            'course_id' => $course->id,
            'title' => $material->title,
            'file_name' => $fileName,
        ]);

        return response()->json([
            'message' => 'Material uploaded successfully.',
            'material' => $material->fresh(['course:id,title', 'classSession:id,title', 'uploader:id,name,email']),
        ], 201);
    }

    /**
     * Show material details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        $material = ClassMaterial::with([
            'course:id,title,instructor_id',
            'classSession:id,title,scheduled_date',
            'uploader:id,name,email',
        ])->findOrFail($id);

        if (! $isAdmin && $material->course?->instructor_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. This material belongs to a course not assigned to you.',
            ], 403);
        }

        return response()->json($material);
    }

    /**
     * Update material metadata.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('manage_materials')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to manage materials.',
            ], 403);
        }

        $material = ClassMaterial::with('course')->findOrFail($id);

        // Tutor must own the material or be course instructor
        if (! $isAdmin && $material->course?->instructor_id !== $user->id && $material->uploaded_by !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. You cannot modify materials for an unassigned course.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'class_session_id' => 'nullable|integer|exists:class_sessions,id',
            'material_type' => 'nullable|string|max:50',
        ]);

        $oldValues = $material->only(['title', 'description', 'class_session_id', 'file_type']);
        $material->update([
            'title' => $validated['title'] ?? $material->title,
            'description' => $validated['description'] ?? $material->description,
            'class_session_id' => array_key_exists('class_session_id', $validated) ? $validated['class_session_id'] : $material->class_session_id,
            'file_type' => $validated['material_type'] ?? $material->file_type,
        ]);

        // Audit Log
        AuditLog::log('updated_material', $material, $oldValues, $material->toArray());

        return response()->json([
            'message' => 'Material updated successfully.',
            'material' => $material->fresh(['course:id,title', 'classSession:id,title', 'uploader:id,name,email']),
        ]);
    }

    /**
     * Delete material.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin' || $user->role === 'super_admin';

        if (! $isAdmin && ! $user->hasPermission('manage_materials')) {
            return response()->json([
                'message' => 'Unauthorized. You do not have permission to delete materials.',
            ], 403);
        }

        $material = ClassMaterial::with('course')->findOrFail($id);

        // Tutor can only delete their own material (cannot delete another tutor's material)
        if (! $isAdmin && $material->uploaded_by !== $user->id) {
            return response()->json([
                'message' => "Unauthorized. You cannot delete another tutor's material.",
            ], 403);
        }

        // Audit Log before deletion
        AuditLog::log('deleted_material', $material, $material->toArray(), null);

        $material->delete();

        return response()->json([
            'message' => 'Material deleted successfully.',
        ]);
    }

    /**
     * Secure authorized material download / access endpoint.
     */
    public function download(Request $request, int $id)
    {
        $user = $request->user();
        $material = ClassMaterial::with('course')->findOrFail($id);
        $courseId = $material->course_id;

        $isAuthorized = false;

        if ($user->role === 'admin' || $user->role === 'super_admin') {
            $isAuthorized = true;
        } elseif ($user->role === 'tutor') {
            $isAuthorized = ($material->course?->instructor_id === $user->id || $material->uploaded_by === $user->id);
        } elseif ($user->role === 'student') {
            $isAuthorized = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->exists();
        }

        if (! $isAuthorized) {
            return response()->json([
                'message' => 'Unauthorized. You do not have access to this protected learning material.',
            ], 403);
        }

        return response()->json([
            'file_name' => $material->file_name,
            'file_path' => $material->file_path,
            'file_type' => $material->file_type,
            'file_size' => $material->file_size,
            'title' => $material->title,
        ]);
    }
}
