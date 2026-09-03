<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTutorPermissionController extends Controller
{
    /**
     * List all tutors with their permissions and assigned courses.
     */
    public function index(Request $request): JsonResponse
    {
        $tutors = User::whereIn('role', ['tutor', 'faculty', 'instructor'])
            ->select(['id', 'name', 'email', 'role', 'status', 'avatar', 'expertise', 'permissions'])
            ->withCount('taughtCourses')
            ->orderBy('name', 'asc')
            ->get()
            ->map(function (User $tutor) {
                return [
                    'id' => $tutor->id,
                    'name' => $tutor->name,
                    'email' => $tutor->email,
                    'role' => $tutor->role,
                    'status' => $tutor->status,
                    'avatar' => $tutor->avatar,
                    'expertise' => $tutor->expertise,
                    'taught_courses_count' => $tutor->taught_courses_count,
                    'permissions' => $tutor->getResolvedPermissions(),
                ];
            });

        return response()->json($tutors);
    }

    /**
     * Get specific tutor permissions.
     */
    public function getPermissions(Request $request, int $id): JsonResponse
    {
        $tutor = User::findOrFail($id);

        return response()->json([
            'id' => $tutor->id,
            'name' => $tutor->name,
            'email' => $tutor->email,
            'permissions' => $tutor->getResolvedPermissions(),
            'default_permissions' => User::defaultTutorPermissions(),
        ]);
    }

    /**
     * Update permissions for a specific tutor.
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $tutor = User::findOrFail($id);

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.view_assigned_courses' => 'sometimes|boolean',
            'permissions.view_assigned_courses' => 'sometimes|boolean',
            'permissions.view_students' => 'sometimes|boolean',
            'permissions.upload_materials' => 'sometimes|boolean',
            'permissions.upload_materials' => 'sometimes|boolean',
            'permissions.manage_materials' => 'sometimes|boolean',
            'permissions.manage_materials' => 'sometimes|boolean',
            'permissions.create_quizzes' => 'sometimes|boolean',
            'permissions.create_quizzes' => 'sometimes|boolean',
            'permissions.edit_quizzes' => 'sometimes|boolean',
            'permissions.edit_quizzes' => 'sometimes|boolean',
            'permissions.delete_quizzes' => 'sometimes|boolean',
            'permissions.delete_quizzes' => 'sometimes|boolean',
            'permissions.publish_quizzes' => 'sometimes|boolean',
            'permissions.publish_quizzes' => 'sometimes|boolean',
            'permissions.view_quiz_results' => 'sometimes|boolean',
            'permissions.view_quiz_results' => 'sometimes|boolean',
        ]);

        $oldPermissions = $tutor->getResolvedPermissions();
        $updatedPermissions = array_merge(
            $tutor->permissions ?? User::defaultTutorPermissions(),
            User::canonicalizePermissions($validated['permissions'])
        );
        $updatedPermissions = array_merge(User::defaultTutorPermissions(), User::canonicalizePermissions($updatedPermissions));

        $tutor->permissions = $updatedPermissions;
        $tutor->save();

        // Audit Log
        AuditLog::log('updated_tutor_permissions', $tutor, $oldPermissions, $updatedPermissions);

        return response()->json([
            'message' => "Permissions for {$tutor->name} updated successfully.",
            'tutor' => [
                'id' => $tutor->id,
                'name' => $tutor->name,
                'permissions' => $tutor->getResolvedPermissions(),
            ],
        ]);
    }
}
