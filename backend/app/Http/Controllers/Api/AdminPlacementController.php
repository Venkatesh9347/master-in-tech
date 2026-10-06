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
            ->paginate($this->perPage($request, 50));

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
     * Audit events that belong to placement / corporate recruitment oversight.
     *
     * Derived from an exhaustive scan of every AuditLog::log() call site in the
     * application — these are the real event names that exist, not invented ones.
     * Grouped by the business area each one describes:
     *
     *   corporate partner lifecycle : CompanyPortalController, AdminCorporatePartnerController
     *   company vacancy lifecycle  : CompanyPortalController, AdminCorporatePartnerController
     *   placement opportunities   : AdminPlacementController
     *   placement applications     : PlacementPortalController, AdminPlacementController,
     *                                CompanyPortalController
     *   placement configuration    : PlacementSettingService
     *   student placement access   : MockInterviewService (StudentPlacementEligibility)
     *
     * Deliberately EXCLUDED, because they are not placement oversight:
     *   - user administration and role assignment (created_user, updated_user,
     *     deleted_user, updated_user_role, updated_tutor_permissions, ...)
     *   - CRM and call recordings (*crm*, converted_crm_lead_to_student,
     *     recorded_lead_payment, accessed_call_recording, ...)
     *   - mock-interview administration (created/updated/deleted_mock_interviewer,
     *     _mock_interview_slot, booked/rescheduled/cancelled_mock_interview,
     *     submitted_mock_interview_evaluation, reassigned_mock_interview_interviewer,
     *     deactivated_mock_interviewer, updated_mock_interview_status)
     *     — those endpoints are admin-only, so exposing their audit trail here
     *       would leak an administration surface that placement_advisor is
     *       deliberately denied.
     *   - payments, refunds, certificates, CMS, batches, live classes, webhooks
     *
     * @var list<string>
     */
    public const PLACEMENT_AUDIT_ACTIONS = [
        // corporate partner lifecycle
        'company_partnership_requested',
        'approved_corporate_partner',
        'rejected_corporate_partner',
        'suspended_corporate_partner',
        'reactivated_corporate_partner',
        // company vacancy lifecycle
        'company_job_created',
        'approved_company_job',
        'rejected_company_job',
        // placement opportunities
        'created_placement_opportunity',
        'updated_placement_opportunity',
        'deleted_placement_opportunity',
        // placement applications and the corporate recruitment pipeline
        'submitted_placement_application',
        'updated_placement_application_status',
        'company_updated_application_status',
        'company_scheduled_interview',
        // placement configuration
        'updated_placement_settings',
        // student placement access
        'placement_dashboard_disabled',
        'placement_dashboard_suspended',
        'overrode_student_placement_eligibility',
    ];

    /**
     * Keys stripped from audit payloads before they are returned.
     *
     * Defence in depth: the allowlist above already guarantees placement context,
     * but this guarantees that no credential-shaped value can ever reach a
     * placement_advisor even if a future placement event logs one.
     *
     * @var list<string>
     */
    private const AUDIT_REDACTED_KEY_PATTERN = '/(password|passwd|token|secret|api[_-]?key|authorization|bearer|credential|session_id|remember_token)/i';

    /**
     * Recursively remove credential-shaped keys from an audit payload.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function redactAuditPayload($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::AUDIT_REDACTED_KEY_PATTERN, $key)) {
                continue;
            }

            $clean[$key] = is_array($item) || is_object($item)
                ? $this->redactAuditPayload((array) $item)
                : $item;
        }

        return $clean;
    }

    /**
     * Placement-scoped audit event stream (READ ONLY).
     *
     * Phase 4 capability #20 — "view placement audit events". Serves only the
     * allowlisted placement actions above; the rest of the audit_logs table is
     * never queried. ip_address and user_agent are withheld as
     * security-sensitive metadata, and payloads are passed through the redactor.
     *
     * This is additive: the existing admin audit surface is untouched.
     */
    public function auditEvents(Request $request)
    {
        $query = AuditLog::query()
            ->whereIn('action', self::PLACEMENT_AUDIT_ACTIONS)
            // Secondary narrowing only. `action` is the primary allowlist and is
            // always applied, so a NULL auditable_type cannot widen exposure —
            // it is required because `updated_placement_settings` legitimately
            // audits a null model (PlacementSettingService L71 passes null).
            // The type clause additionally prevents a future unrelated model
            // from reusing an allowlisted action name and leaking through.
            ->where(function ($q) {
                $q->whereIn('auditable_type', [
                    \App\Models\PlacementOpportunity::class,
                    \App\Models\PlacementApplication::class,
                    \App\Models\PlacementInterview::class,
                    \App\Models\Company::class,
                    \App\Models\StudentPlacementEligibility::class,
                ])->orWhereNull('auditable_type');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('action')) {
            $requested = (string) $request->action;

            if (! in_array($requested, self::PLACEMENT_AUDIT_ACTIONS, true)) {
                abort(422, 'Unknown placement audit action.');
            }

            $query->where('action', $requested);
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', (string) $request->auditable_type);
        }

        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', (int) $request->auditable_id);
        }

        $events = $query->paginate(min((int) ($request->input('per_page', 30)), 100));

        // Project to an explicit, minimal column set and sanitise the payloads.
        $events->through(function (AuditLog $event) {
            return [
                'id' => $event->id,
                'action' => $event->action,
                'actor_id' => $event->user_id,
                'actor_name' => $event->user_name,
                'auditable_type' => $event->auditable_type ? class_basename($event->auditable_type) : null,
                'auditable_id' => $event->auditable_id,
                'old_values' => $this->redactAuditPayload($event->old_values),
                'new_values' => $this->redactAuditPayload($event->new_values),
                'created_at' => optional($event->created_at)->toIso8601String(),
            ];
        });

        return response()->json($events);
    }

    /**
     * Placement interview pipeline (READ ONLY).
     *
     * Phase 4 capability #17 — "view interview pipeline". Reuses
     * PlacementInterview and its existing relationships; no new model, table or
     * migration. Strictly read-only: no create, reschedule, cancel or delete
     * path is exposed here — company-side scheduling remains the only write
     * path and stays behind EnsureUserIsCompany.
     *
     * Withheld deliberately:
     *   meeting_link       — join URLs can embed credentials
     *   admin_notes        — internal administrative notes
     *   interviewer_notes  — company-internal interviewer notes
     *   instructions       — company-internal instructions
     *   candidate email / phone / resume_url — not needed for pipeline oversight
     */
    public function interviewPipeline(Request $request)
    {
        $query = \App\Models\PlacementInterview::query()
            ->with([
                'opportunity:id,title,company_name,location,status',
                'company:id,name,status',
                'candidate:id,name',
                'application:id,placement_opportunity_id,user_id,status,student_name',
            ])
            ->orderByDesc('interview_date')
            ->orderByDesc('id');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', (string) $request->status);
        }

        if ($request->filled('placement_opportunity_id') && $request->placement_opportunity_id !== 'all') {
            $query->where('placement_opportunity_id', (int) $request->placement_opportunity_id);
        }

        if ($request->filled('company_id') && $request->company_id !== 'all') {
            $query->where('company_id', (int) $request->company_id);
        }

        $interviews = $query->paginate(min((int) ($request->input('per_page', 30)), 100));

        $interviews->through(function ($interview) {
            return [
                'id' => $interview->id,
                'interview_date' => optional($interview->interview_date)->toIso8601String(),
                'interview_type' => $interview->interview_type,
                'location' => $interview->location,
                'status' => $interview->status,
                'technical_score' => $interview->technical_score,
                'communication_score' => $interview->communication_score,
                'overall_score' => $interview->overall_score,
                'recommendation' => $interview->recommendation,
                'feedback' => $interview->feedback,
                'opportunity' => $interview->opportunity ? [
                    'id' => $interview->opportunity->id,
                    'title' => $interview->opportunity->title,
                    'company_name' => $interview->opportunity->company_name,
                    'status' => $interview->opportunity->status,
                ] : null,
                'company' => $interview->company ? [
                    'id' => $interview->company->id,
                    'name' => $interview->company->name,
                    'status' => $interview->company->status,
                ] : null,
                'candidate' => $interview->candidate ? [
                    'id' => $interview->candidate->id,
                    'name' => $interview->candidate->name,
                ] : null,
                'application' => $interview->application ? [
                    'id' => $interview->application->id,
                    'status' => $interview->application->status,
                    'student_name' => $interview->application->student_name,
                ] : null,
            ];
        });

        return response()->json($interviews);
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
            ->paginate($this->perPage($request, 50));

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
