<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Event;
use App\Models\User;
use App\Services\Enrollment\EnrollmentAccess;
use App\Services\Enrollment\EnrollmentPaymentGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    /**
     * Get platform overview statistics (admin only).
     */
    public function stats()
    {
        $totalUsers = User::count();
        $totalStudents = User::where('role', 'student')->orWhereNull('role')->count();
        $totalTutors = User::where('role', 'tutor')->count();
        $totalAdmins = User::where('role', 'admin')->count();
        $totalCourses = Course::count();
        $totalEnrollments = CourseEnrollment::count();
        $totalEvents = Event::count();
        $totalCertificates = Certificate::count();
        $totalSubmissions = AssignmentSubmission::count();
        $pendingSubmissions = AssignmentSubmission::where('status', 'submitted')->count();

        return response()->json([
            'total_users' => $totalUsers,
            'total_students' => $totalStudents,
            'total_tutors' => $totalTutors,
            'total_admins' => $totalAdmins,
            'total_courses' => $totalCourses,
            'total_enrollments' => $totalEnrollments,
            'total_events' => $totalEvents,
            'total_certificates' => $totalCertificates,
            'total_submissions' => $totalSubmissions,
            'pending_submissions' => $pendingSubmissions,
        ]);
    }

    /**
     * List all users with search and role filter.
     */
    public function index(Request $request)
    {
        $query = User::query()->withCount(['enrollments', 'taughtCourses']);

        if ($request->has('role') && in_array($request->role, ['student', 'tutor', 'faculty', 'admin', 'super_admin', 'counsellor', 'telecaller', 'course_advisor'], true)) {
            $query->where('role', $request->role);
        }

        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('headline', 'like', "%{$search}%")
                    ->orWhere('expertise', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('created_at', 'desc');

        $limit = $this->limitCap($request);
        if ($limit !== null) {
            $users->limit($limit);
        }

        return response()->json($users->get());
    }

    /**
     * Show detailed user profile with enrollments, taught courses, and certificates (admin only).
     */
    public function show(User $user)
    {
        $user->load([
            'enrollments.course:id,title,category',
            'taughtCourses:id,title,category,is_published',
            'certificates.course:id,title',
        ])->loadCount(['enrollments', 'taughtCourses']);

        return response()->json($user);
    }

    /**
     * Create a new user (Tutor, Student, or Admin) from Admin Studio.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:6',
            'role' => 'required|string|in:student,tutor,counsellor,telecaller,course_advisor,admin,super_admin',
            'student_id' => 'nullable|string|max:50|unique:users,student_id',
            'status' => 'nullable|string|in:active,pending,disabled',
            'phone' => 'nullable|string|max:30',
            'course_id' => 'nullable|exists:courses,id',
            'enquiry_id' => 'nullable|exists:enquiries,id',
            'override_reason' => 'nullable|string|min:10|max:1000',
            'headline' => 'nullable|string|max:255',
            'expertise' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:2000',
        ]);

        $this->denyUnlessSuperAdminGrantAllowed($request, $validated['role']);

        $password = ! empty($validated['password'])
            ? Hash::make($validated['password'])
            : Hash::make(\Illuminate\Support\Str::random(16));

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'password' => $password,
            'status' => $validated['status'] ?? 'active',
            'student_id' => $validated['student_id'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'headline' => $validated['headline'] ?? null,
            'expertise' => $validated['expertise'] ?? null,
            'bio' => $validated['bio'] ?? null,
        ]);

        // HIGH-7: role is deliberately NOT mass-assignable; set it explicitly
        // via this policy-controlled admin path.
        $user->forceFill(['role' => $validated['role']])->save();

        // Auto-assign student ID if role is student and student_id is empty
        if ($user->role === 'student' && empty($user->student_id)) {
            $user->student_id = 'STU-' . (1000 + $user->id);
            $user->save();
        }

        // Auto-enroll in course if specified (B3 pay-before-classroom: pending
        // without verified payment or an explicit admin override).
        if (! empty($validated['course_id'])) {
            $overrideReason = EnrollmentPaymentGate::extractOverrideReason(
                $request->user(),
                $validated['override_reason'] ?? null
            );
            $gate = EnrollmentPaymentGate::resolveStatus(
                (int) $user->id,
                (int) $validated['course_id'],
                $request->user(),
                $overrideReason
            );

            $courseEnrollment = CourseEnrollment::firstOrCreate([
                'user_id' => $user->id,
                'course_id' => $validated['course_id'],
            ], [
                'status' => $gate['status'],
                'enrolled_at' => now(),
            ]);

            if ($gate['via_override']) {
                EnrollmentAccess::logOverride(
                    $request->user(),
                    (int) $user->id,
                    (int) $validated['course_id'],
                    null,
                    $courseEnrollment->status,
                    (string) $overrideReason,
                    $gate['payment_verified'],
                    $courseEnrollment
                );
            }
        }

        // Link enquiry if provided
        if (! empty($validated['enquiry_id'])) {
            $enquiry = \App\Models\Enquiry::find($validated['enquiry_id']);
            if ($enquiry) {
                $enquiry->update([
                    'status' => \App\Models\Enquiry::STATUS_ENROLLED,
                    'enrolled_user_id' => $user->id,
                    'enrolled_at' => now(),
                ]);
            }
        }

        AuditLog::log('created_user', $user, null, $user->only([
            'id', 'name', 'email', 'role', 'status', 'student_id', 'phone',
        ]));

        return response()->json([
            'message' => "User ({$user->role}) created successfully by Administrator.",
            'user' => $user->fresh()->loadCount(['enrollments', 'taughtCourses'])->load('enrollments.course:id,title'),
        ], 201);
    }

    /**
     * Update an existing user's details and profile.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|string|email|max:255|unique:users,email,{$user->id}",
            'student_id' => "nullable|string|max:50|unique:users,student_id,{$user->id}",
            'status' => 'nullable|string|in:active,pending,disabled',
            'role' => 'sometimes|required|string|in:student,tutor,counsellor,telecaller,course_advisor,admin,super_admin',
            'phone' => 'nullable|string|max:30',
            'headline' => 'nullable|string|max:255',
            'expertise' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:2000',
            'password' => 'nullable|string|min:6',
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }

        // HIGH-7: role is not editable via the generic update path; it is only
        // managed through the dedicated updateRole policy-controlled endpoint.
        unset($validated['role']);

        $old = $user->only([
            'id', 'name', 'email', 'role', 'status', 'student_id', 'phone',
        ]);

        $user->update($validated);

        AuditLog::log('updated_user', $user, $old, $user->fresh()->only([
            'id', 'name', 'email', 'role', 'status', 'student_id', 'phone',
        ]));

        return response()->json([
            'message' => "User details updated successfully.",
            'user' => $user->fresh()->loadCount(['enrollments', 'taughtCourses'])->load('enrollments.course:id,title'),
        ]);
    }

    /**
     * Update a user's role.
     */
    public function updateRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => 'required|in:student,tutor,counsellor,telecaller,course_advisor,admin,super_admin',
        ]);

        $this->denyUnlessSuperAdminGrantAllowed($request, $validated['role']);

        $oldRole = $user->role;

        // HIGH-7: explicit policy-controlled role update bypasses mass assignment.
        $user->forceFill(['role' => $validated['role']])->save();

        AuditLog::log('updated_user_role', $user, [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $oldRole,
        ], [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => "User role successfully updated to {$validated['role']}.",
            'user' => $user->fresh()->loadCount(['enrollments', 'taughtCourses']),
        ]);
    }

    /**
     * Delete a user account (admin only).
     */
    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Cannot delete your own administrator account.'], 403);
        }

        $old = $user->only([
            'id', 'name', 'email', 'role', 'status', 'student_id',
        ]);

        $user->delete();

        AuditLog::log('deleted_user', null, $old, null);

        return response()->json([
            'message' => 'User account removed successfully.',
        ]);
    }

    /**
     * Only a super_admin may grant the super_admin role (privilege-escalation guard).
     */
    private function denyUnlessSuperAdminGrantAllowed(Request $request, string $targetRole): void
    {
        if ($targetRole === 'super_admin' && ($request->user()?->role !== 'super_admin')) {
            abort(403, 'Only a super administrator can grant the super_admin role.');
        }
    }

    /**
     * Get detailed learning progress for a student across all courses (admin only).
     */
    public function studentProgress(User $user)
    {
        $enrollments = CourseEnrollment::where('user_id', $user->id)
            ->with(['course:id,title,instructor,category'])
            ->get();

        $lessonProgress = \App\Models\LessonProgress::where('user_id', $user->id)
            ->with(['lesson:id,title,type,course_id'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $quizAttempts = \App\Models\QuizAttempt::where('user_id', $user->id)
            ->with(['quiz:id,title,passing_score', 'course:id,title'])
            ->orderBy('created_at', 'desc')
            ->get();

        $submissions = AssignmentSubmission::where('user_id', $user->id)
            ->with(['assignment:id,title,max_marks', 'course:id,title'])
            ->orderBy('created_at', 'desc')
            ->get();

        $activityLogs = \App\Models\LearningActivityLog::where('user_id', $user->id)
            ->with(['course:id,title'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'enrollments' => $enrollments,
            'lesson_progress' => $lessonProgress,
            'quiz_attempts' => $quizAttempts,
            'assignment_submissions' => $submissions,
            'recent_activity' => $activityLogs,
        ]);
    }

    /**
     * Get recent learning activity audit logs (admin only).
     */
    public function activityLogs(Request $request)
    {
        $query = \App\Models\LearningActivityLog::with(['user:id,name,email', 'course:id,title'])
            ->orderBy('created_at', 'desc');

        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        return response()->json($query->paginate(30));
    }
}
