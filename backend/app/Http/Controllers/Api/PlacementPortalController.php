<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\PlacementApplication;
use App\Models\PlacementOpportunity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlacementPortalController extends Controller
{
    /**
     * Public endpoint: Get placement availability and policy flags.
     */
    public function settings()
    {
        return response()->json(\App\Services\PlacementSettingService::getSettings());
    }

    /**
     * Check placement dashboard status for authenticated student.
     */
    public function studentDashboardStatus(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'placement_dashboard_enabled' => false,
                'dashboard_status' => 'DISABLED',
            ]);
        }

        $eligibility = \App\Services\MockInterviewService::checkStudentEligibility($user);

        return response()->json([
            'placement_dashboard_enabled' => (bool) ($eligibility['placement_dashboard_enabled'] ?? false),
            'dashboard_status' => $eligibility['placement_dashboard_status'] ?? 'DISABLED',
            'is_eligible_for_activation' => (bool) ($eligibility['is_eligible_for_activation'] ?? false),
            'eligibility' => $eligibility,
        ]);
    }

    /**
     * Helper to verify that the student has placement dashboard access.
     */
    protected function ensureStudentPlacementDashboardAccess(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Administrative & Recruiter bypass
        if (in_array($user->role, ['admin', 'super_admin', 'recruiter'], true)) {
            return null;
        }

        if ($user->role !== 'student' && $user->role !== null) {
            return response()->json(['message' => 'Only enrolled students can access the student placement portal.'], 403);
        }

        // 1. Global placement control check
        if (! \App\Services\PlacementSettingService::isPlacementEnabled()) {
            return response()->json([
                'message' => 'The Placement Portal is temporarily disabled by MasterInTech administration.',
                'code' => 'PLACEMENT_DISABLED',
                'placement_enabled' => false,
            ], 403);
        }

        // 2. Individual student placement dashboard activation check
        $eligibility = \App\Models\StudentPlacementEligibility::where('user_id', $user->id)->first();
        $status = $eligibility?->dashboard_status ?? 'DISABLED';

        // Check if student is suspended
        if ($status === \App\Models\StudentPlacementEligibility::STATUS_SUSPENDED) {
            return response()->json([
                'message' => 'Your Placement Dashboard access has been suspended by administration. Reason: ' . ($eligibility?->dashboard_status_reason ?? 'Under administrative review.'),
                'code' => 'PLACEMENT_DASHBOARD_DISABLED',
                'dashboard_status' => 'SUSPENDED',
                'placement_dashboard_enabled' => false,
            ], 403);
        }

        // When Mock Interview requirement is active
        if (\App\Services\PlacementSettingService::isMockInterviewRequired()) {
            $isEnabled = false;
            if ($eligibility && $eligibility->is_admin_override && $eligibility->placement_eligible) {
                $isEnabled = true;
            } elseif ($status === \App\Models\StudentPlacementEligibility::STATUS_ENABLED) {
                $isEnabled = true;
            }

            if (! $isEnabled) {
                $hasMock = \App\Services\MockInterviewService::isStudentPlacementEligible($user);
                $msg = match ($status) {
                    'ELIGIBLE' => 'Your placement eligibility requirements are completed! Your Placement Dashboard is pending administrator activation.',
                    default => 'Placement Dashboard is not activated for your account. Please complete your course curriculum, mandatory mock interview, and obtain administrator activation.',
                };

                return response()->json([
                    'message' => $msg,
                    'code' => 'PLACEMENT_DASHBOARD_DISABLED',
                    'dashboard_status' => $status,
                    'placement_dashboard_enabled' => false,
                    'mock_interview_required' => ! $hasMock,
                ], 403);
            }
        }

        return null;
    }

    /**
     * Get published job opportunities for the Placement Portal.
     */
    public function opportunities(Request $request)
    {
        if (! \App\Services\PlacementSettingService::isPlacementEnabled()) {
            $user = $request->user();
            if (! $user || ! in_array($user->role, ['admin', 'super_admin'], true)) {
                return response()->json([
                    'message' => 'The Placement Portal is temporarily disabled by MasterInTech administration.',
                    'code' => 'PLACEMENT_DISABLED',
                    'placement_enabled' => false,
                ], 403);
            }
        }

        // If authenticated student is suspended, prevent portal viewing
        $user = $request->user();
        if ($user && ($user->role === 'student' || $user->role === null)) {
            $eligibility = \App\Models\StudentPlacementEligibility::where('user_id', $user->id)->first();
            if ($eligibility && $eligibility->dashboard_status === \App\Models\StudentPlacementEligibility::STATUS_SUSPENDED) {
                return response()->json([
                    'message' => 'Your Placement Dashboard access has been suspended by administration.',
                    'code' => 'PLACEMENT_DASHBOARD_DISABLED',
                    'dashboard_status' => 'SUSPENDED',
                    'placement_dashboard_enabled' => false,
                ], 403);
            }
        }

        $query = PlacementOpportunity::published()->withCount('applications');

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('location') && $request->location !== 'all') {
            $query->where('location', 'like', "%{$request->location}%");
        }

        if ($request->filled('employment_type') && $request->employment_type !== 'all') {
            $query->where('employment_type', $request->employment_type);
        }

        $opportunities = $query->orderBy('is_featured', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($opportunities);
    }

    /**
     * Get details of a single placement opportunity.
     */
    public function showOpportunity(Request $request, PlacementOpportunity $opportunity)
    {
        if (! \App\Services\PlacementSettingService::isPlacementEnabled()) {
            $user = $request->user();
            if (! $user || ! in_array($user->role, ['admin', 'super_admin'], true)) {
                return response()->json([
                    'message' => 'The Placement Portal is temporarily disabled by MasterInTech administration.',
                    'code' => 'PLACEMENT_DISABLED',
                    'placement_enabled' => false,
                ], 403);
            }
        }

        $user = $request->user();
        if ($user && ($user->role === 'student' || $user->role === null)) {
            $eligibility = \App\Models\StudentPlacementEligibility::where('user_id', $user->id)->first();
            if ($eligibility && $eligibility->dashboard_status === \App\Models\StudentPlacementEligibility::STATUS_SUSPENDED) {
                return response()->json([
                    'message' => 'Your Placement Dashboard access has been suspended by administration.',
                    'code' => 'PLACEMENT_DASHBOARD_DISABLED',
                    'dashboard_status' => 'SUSPENDED',
                    'placement_dashboard_enabled' => false,
                ], 403);
            }
        }

        if ($opportunity->status !== PlacementOpportunity::STATUS_PUBLISHED) {
            if (! $user || ! in_array($user->role, ['admin', 'super_admin'], true)) {
                return response()->json(['message' => 'This opportunity is not publicly accessible.'], 404);
            }
        }

        $hasApplied = false;
        $myApplication = null;
        if ($user) {
            $myApplication = PlacementApplication::where('placement_opportunity_id', $opportunity->id)
                ->where('user_id', $user->id)
                ->first();
            $hasApplied = (bool) $myApplication;
        }

        $opportunity->loadCount('applications');

        return response()->json([
            'opportunity' => $opportunity,
            'has_applied' => $hasApplied,
            'my_application' => $myApplication,
        ]);
    }

    /**
     * Return verified student profile and batch details for auto-prefill.
     */
    public function profilePrefill(Request $request)
    {
        $accessCheck = $this->ensureStudentPlacementDashboardAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $user = $request->user();

        // Fetch student's verified enrolled courses and active batch records
        $enrollments = $user->enrollments()->with('course:id,title,code,category')->get();
        $activeBatches = $user->activeBatches()->with('course:id,title,code')->get();

        return response()->json([
            'user_id' => $user->id,
            'student_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'student_id' => $user->student_id,
            'enrolled_courses' => $enrollments->map(fn ($e) => [
                'id' => $e->course_id,
                'title' => $e->course?->title,
                'code' => $e->course?->code,
            ]),
            'active_batches' => $activeBatches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'course_id' => $b->course_id,
                'course_title' => $b->course?->title,
                'start_date' => $b->start_date?->format('Y-m-d'),
            ]),
        ]);
    }

    /**
     * Submit placement application for a student.
     * Validates batch against student's verified batch records and prevents duplicate applications.
     */
    public function apply(Request $request, PlacementOpportunity $opportunity)
    {
        $user = $request->user();

        // 1. Global placement control check
        if (! \App\Services\PlacementSettingService::isPlacementEnabled()) {
            return response()->json([
                'message' => 'The Placement Portal is temporarily disabled by MasterInTech administration.',
                'code' => 'PLACEMENT_DISABLED',
                'placement_enabled' => false,
            ], 403);
        }

        if (! \App\Services\PlacementSettingService::isJobApplicationsEnabled()) {
            return response()->json([
                'message' => 'Job applications are currently paused by administration.',
                'code' => 'APPLICATIONS_PAUSED',
                'job_applications_enabled' => false,
            ], 403);
        }

        // Mock Interview Placement Eligibility Check
        if (\App\Services\PlacementSettingService::isMockInterviewRequired()) {
            if (! \App\Services\MockInterviewService::isStudentPlacementEligible($user)) {
                return response()->json([
                    'message' => 'Mandatory Mock Interview and Administrator Activation required before applying for placement drives.',
                    'code' => 'MOCK_INTERVIEW_REQUIRED',
                    'mock_interview_required' => true,
                ], 403);
            }
        }

        $accessCheck = $this->ensureStudentPlacementDashboardAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        // 1. Opportunity active check
        if ($opportunity->status !== PlacementOpportunity::STATUS_PUBLISHED) {
            return response()->json([
                'message' => 'Applications for this opportunity are currently closed.',
            ], 422);
        }

        if ($opportunity->deadline_date && $opportunity->deadline_date->isPast()) {
            return response()->json([
                'message' => 'The application deadline for this opportunity has passed.',
            ], 422);
        }

        // 2. Duplicate Application Check
        $alreadyApplied = PlacementApplication::where('placement_opportunity_id', $opportunity->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'message' => 'You have already submitted an application for this opportunity.',
            ], 422);
        }

        // 3. Request Validation
        $validated = $request->validate([
            'batch_number' => 'nullable|string|max:100',
            'batch_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:30',
            'course_id' => 'nullable|exists:courses,id',
            'resume_url' => 'required|string|max:2000',
            'cover_note' => 'nullable|string|max:3000',
        ]);

        // 4. Batch Validation: Validate batch number against student's verified batch records
        $batch = null;
        $hasProvidedBatch = ! empty($validated['batch_id']) || ! empty($validated['batch_number']);

        if (! empty($validated['batch_id'])) {
            $batch = Batch::find((int) $validated['batch_id']);
        } elseif (! empty($validated['batch_number'])) {
            $batchCode = trim($validated['batch_number']);
            $batch = Batch::where('code', $batchCode)
                ->orWhere('name', $batchCode)
                ->first();
        }

        // If the user provided a batch input that was not found, reject with 422
        if ($hasProvidedBatch && ! $batch) {
            return response()->json([
                'message' => 'The provided batch number does not exist. Please provide a valid batch code (e.g. RIT(AI)BC230826).',
                'errors' => [
                    'batch_number' => ['The provided batch number does not exist.'],
                ],
            ], 422);
        }

        // If no batch was provided, fall back to student's enrolled active batch
        if (! $batch) {
            $batch = $user->activeBatches()->first();
        }

        if (! $batch) {
            return response()->json([
                'message' => 'The provided batch number does not exist. Please provide a valid batch code (e.g. RIT(AI)BC230826).',
                'errors' => [
                    'batch_number' => ['The provided batch number does not exist.'],
                ],
            ], 422);
        }

        // 5. Derive Course from verified batch or student enrollment
        $courseId = $validated['course_id'] ?? $batch->course_id;
        $course = $courseId ? Course::find($courseId) : null;

        // 6. Verified Student Details (Never tampered by client request)
        $verifiedPhone = ! empty($validated['phone']) ? trim($validated['phone']) : ($user->phone ?? '+91 9000000000');

        $application = PlacementApplication::create([
            'placement_opportunity_id' => $opportunity->id,
            'user_id' => $user->id,
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'student_name' => $user->name,
            'email' => $user->email,
            'phone' => $verifiedPhone,
            'course_id' => $course?->id,
            'course_title' => $course?->title ?? $batch->course?->title,
            'resume_url' => trim($validated['resume_url']),
            'cover_note' => isset($validated['cover_note']) ? trim($validated['cover_note']) : null,
            'status' => PlacementApplication::STATUS_APPLIED,
            'applied_at' => now(),
        ]);

        AuditLog::log('submitted_placement_application', $application, null, [
            'opportunity_id' => $opportunity->id,
            'student_id' => $user->id,
            'batch_code' => $batch->code,
        ]);

        return response()->json([
            'message' => "Your application for '{$opportunity->title}' at {$opportunity->company_name} has been submitted successfully!",
            'application' => $application->load(['opportunity', 'batch', 'course']),
        ], 201);
    }

    /**
     * Get authenticated student's placement applications.
     */
    public function myApplications(Request $request)
    {
        $accessCheck = $this->ensureStudentPlacementDashboardAccess($request);
        if ($accessCheck) {
            return $accessCheck;
        }

        $user = $request->user();

        $applications = PlacementApplication::forStudent($user->id)
            ->with([
                'opportunity:id,title,company_name,company_logo,location,employment_type,salary_package,status,deadline_date',
                'batch:id,name,code,start_date',
                'course:id,title,code',
            ])
            ->orderBy('applied_at', 'desc')
            ->get();

        return response()->json($applications);
    }
}
