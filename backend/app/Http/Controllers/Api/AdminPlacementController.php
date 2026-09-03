<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\PlacementApplication;
use App\Models\PlacementOpportunity;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminPlacementController extends Controller
{
    /**
     * Get aggregated placement dashboard metrics.
     */
    public function stats()
    {
        $totalOpportunities = PlacementOpportunity::count();
        $activeDrives = PlacementOpportunity::published()
            ->where(function ($q) {
                $q->whereNull('deadline_date')
                    ->orWhere('deadline_date', '>=', Carbon::today());
            })->count();

        $totalApplications = PlacementApplication::count();
        $underReview = PlacementApplication::where('status', PlacementApplication::STATUS_UNDER_REVIEW)->count();
        $shortlisted = PlacementApplication::where('status', PlacementApplication::STATUS_SHORTLISTED)->count();
        $interviewScheduled = PlacementApplication::where('status', PlacementApplication::STATUS_INTERVIEW_SCHEDULED)->count();
        $selected = PlacementApplication::where('status', PlacementApplication::STATUS_SELECTED)->count();
        $joined = PlacementApplication::where('status', PlacementApplication::STATUS_JOINED)->count();
        $rejected = PlacementApplication::where('status', PlacementApplication::STATUS_REJECTED)->count();

        return response()->json([
            'total_opportunities' => $totalOpportunities,
            'active_drives' => $activeDrives,
            'total_applications' => $totalApplications,
            'under_review' => $underReview,
            'shortlisted' => $shortlisted,
            'interview_scheduled' => $interviewScheduled,
            'selected' => $selected,
            'joined' => $joined,
            'rejected' => $rejected,
        ]);
    }

    /**
     * List all placement opportunities (including drafts and closed) with filters.
     */
    public function opportunities(Request $request)
    {
        $query = PlacementOpportunity::withCount('applications');

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $opportunities = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json($opportunities);
    }

    /**
     * Store a new placement opportunity.
     */
    public function storeOpportunity(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|string|max:500',
            'job_code' => 'nullable|string|max:50',
            'location' => 'required|string|max:255',
            'employment_type' => 'required|string|max:50',
            'salary_package' => 'nullable|string|max:100',
            'experience_required' => 'nullable|string|max:100',
            'eligibility' => 'nullable|string|max:1000',
            'eligible_courses' => 'nullable|array',
            'eligible_batches' => 'nullable|array',
            'skills_required' => 'nullable|array',
            'description' => 'required|string',
            'selection_process' => 'nullable|string',
            'openings_count' => 'nullable|integer|min:1',
            'deadline_date' => 'nullable|date',
            'status' => 'nullable|string|in:published,draft,closed',
            'is_featured' => 'nullable|boolean',
        ]);

        $validated['created_by'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? PlacementOpportunity::STATUS_PUBLISHED;

        $opportunity = PlacementOpportunity::create($validated);

        AuditLog::log('created_placement_opportunity', $opportunity, null, $opportunity->toArray());

        return response()->json([
            'message' => "Opportunity '{$opportunity->title}' created successfully.",
            'opportunity' => $opportunity,
        ], 201);
    }

    /**
     * Show single opportunity with full details.
     */
    public function showOpportunity(PlacementOpportunity $opportunity)
    {
        $opportunity->loadCount('applications');
        return response()->json($opportunity);
    }

    /**
     * Update placement opportunity.
     */
    public function updateOpportunity(Request $request, PlacementOpportunity $opportunity)
    {
        $old = $opportunity->toArray();

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'company_name' => 'sometimes|required|string|max:255',
            'company_logo' => 'nullable|string|max:500',
            'job_code' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'employment_type' => 'nullable|string|max:50',
            'salary_package' => 'nullable|string|max:100',
            'experience_required' => 'nullable|string|max:100',
            'eligibility' => 'nullable|string|max:1000',
            'eligible_courses' => 'nullable|array',
            'eligible_batches' => 'nullable|array',
            'skills_required' => 'nullable|array',
            'description' => 'sometimes|required|string',
            'selection_process' => 'nullable|string',
            'openings_count' => 'nullable|integer|min:1',
            'deadline_date' => 'nullable|date',
            'status' => 'nullable|string|in:published,draft,closed',
            'is_featured' => 'nullable|boolean',
        ]);

        $opportunity->update($validated);

        AuditLog::log('updated_placement_opportunity', $opportunity, $old, $opportunity->toArray());

        return response()->json([
            'message' => 'Opportunity updated successfully.',
            'opportunity' => $opportunity,
        ]);
    }

    /**
     * Delete placement opportunity.
     */
    public function destroyOpportunity(PlacementOpportunity $opportunity)
    {
        $old = $opportunity->toArray();
        $title = $opportunity->title;
        $opportunity->delete();

        AuditLog::log('deleted_placement_opportunity', null, $old, null);

        return response()->json([
            'message' => "Opportunity '{$title}' deleted successfully.",
        ]);
    }

    /**
     * List all student applications with filters.
     */
    public function applications(Request $request)
    {
        $query = PlacementApplication::with([
            'opportunity:id,title,company_name,location,salary_package,status',
            'user:id,name,email,phone,student_id,avatar',
            'batch:id,name,code,start_date',
            'course:id,title,code',
            'reviewer:id,name,email',
        ]);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('placement_opportunity_id') && $request->placement_opportunity_id !== 'all') {
            $query->where('placement_opportunity_id', (int) $request->placement_opportunity_id);
        }

        if ($request->filled('batch_id') && $request->batch_id !== 'all') {
            $query->forBatch((int) $request->batch_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->forStatus($request->status);
        }

        $applications = $query->orderBy('applied_at', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json($applications);
    }

    /**
     * Update application status (shortlisted, interview_scheduled, selected, etc.).
     */
    public function updateApplicationStatus(Request $request, PlacementApplication $application)
    {
        $old = $application->toArray();

        $validated = $request->validate([
            'status' => 'required|string|in:applied,under_review,shortlisted,interview_scheduled,selected,rejected,joined',
            'interview_date' => 'nullable|date',
            'interview_notes' => 'nullable|string|max:2000',
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $validated['reviewed_by'] = $request->user()->id;

        $application->update($validated);

        AuditLog::log('updated_placement_application_status', $application, $old, $application->toArray());

        return response()->json([
            'message' => "Application status updated to " . strtoupper(str_replace('_', ' ', $validated['status'])) . ".",
            'application' => $application->load(['opportunity', 'user', 'batch', 'course', 'reviewer']),
        ]);
    }

    /**
     * Get Admin Placement Settings.
     */
    public function getSettings()
    {
        return response()->json(\App\Services\PlacementSettingService::getSettings());
    }

    /**
     * Update Admin Placement Settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'placement_enabled' => 'nullable|boolean',
            'mock_interview_required' => 'nullable|boolean',
            'job_applications_enabled' => 'nullable|boolean',
            'placementEnabled' => 'nullable|boolean',
            'mockInterviewRequired' => 'nullable|boolean',
            'jobApplicationsEnabled' => 'nullable|boolean',
        ]);

        $settings = \App\Services\PlacementSettingService::updateSettings($validated, $request->user());

        return response()->json([
            'message' => 'Placement settings updated successfully.',
            'settings' => $settings,
            'placement_enabled' => $settings['placement_enabled'],
            'mock_interview_required' => $settings['mock_interview_required'],
            'job_applications_enabled' => $settings['job_applications_enabled'],
            'placementEnabled' => $settings['placementEnabled'],
            'mockInterviewRequired' => $settings['mockInterviewRequired'],
            'jobApplicationsEnabled' => $settings['jobApplicationsEnabled'],
        ]);
    }
}
