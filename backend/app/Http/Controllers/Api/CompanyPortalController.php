<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlacementApplication;
use App\Models\PlacementInterview;
use App\Models\PlacementOpportunity;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CompanyPortalController extends Controller
{
    /**
     * Public endpoint: Submit Partnership Request from /corporate-partner.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'industry' => 'required|string|max:255',
            'company_size' => 'nullable|string|max:100',
            'hr_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:companies,email',
            'phone' => 'required|string|max:30',
            'location' => 'required|string|max:255',
            'hiring_technologies' => 'nullable|array',
            'hiring_requirements' => 'nullable|string|max:3000',
            'message' => 'nullable|string|max:3000',
        ]);

        $validated['status'] = Company::STATUS_PENDING;

        $company = Company::create($validated);

        AuditLog::log('company_partnership_requested', $company, null, [
            'company_name' => $company->name,
            'email' => $company->email,
            'hr_name' => $company->hr_name,
        ]);

        return response()->json([
            'message' => "Thank you! Your partnership request for '{$company->name}' has been submitted. The MasterInTech Placement Cell will review your application and contact you with portal access credentials.",
            'company' => $company,
        ], 201);
    }

    /**
     * Authenticated Company Dashboard KPIs.
     */
    public function dashboard(Request $request)
    {
        $company = $request->user()->company;
        $companyId = $company->id;

        $activeJobs = PlacementOpportunity::forCompany($companyId)
            ->where('status', PlacementOpportunity::STATUS_PUBLISHED)
            ->count();

        $totalJobs = PlacementOpportunity::forCompany($companyId)->count();

        $totalApplications = PlacementApplication::forCompany($companyId)->count();

        $underReview = PlacementApplication::forCompany($companyId)
            ->where('status', PlacementApplication::STATUS_UNDER_REVIEW)
            ->count();

        $shortlisted = PlacementApplication::forCompany($companyId)
            ->where('status', PlacementApplication::STATUS_SHORTLISTED)
            ->count();

        $upcomingInterviews = PlacementInterview::forCompany($companyId)
            ->where('status', PlacementInterview::STATUS_SCHEDULED)
            ->where('interview_date', '>=', now())
            ->count();

        $selectedCandidates = PlacementApplication::forCompany($companyId)
            ->whereIn('status', [PlacementApplication::STATUS_SELECTED, PlacementApplication::STATUS_JOINED])
            ->count();

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'logo' => $company->logo,
                'status' => $company->status,
                'industry' => $company->industry,
            ],
            'active_jobs' => $activeJobs,
            'total_jobs' => $totalJobs,
            'total_applications' => $totalApplications,
            'under_review' => $underReview,
            'shortlisted' => $shortlisted,
            'upcoming_interviews' => $upcomingInterviews,
            'selected_candidates' => $selectedCandidates,
        ]);
    }

    /**
     * Get company profile.
     */
    public function profile(Request $request)
    {
        $company = $request->user()->company;
        return response()->json($company);
    }

    /**
     * Update company profile (cannot change approval status).
     */
    public function updateProfile(Request $request)
    {
        $company = $request->user()->company;

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'logo' => 'nullable|string|max:500',
            'website' => 'nullable|url|max:255',
            'industry' => 'nullable|string|max:255',
            'company_size' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:3000',
            'location' => 'nullable|string|max:255',
            'hr_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'hiring_technologies' => 'nullable|array',
            'hiring_requirements' => 'nullable|string|max:3000',
        ]);

        // Guard against updating status
        unset($validated['status'], $validated['approved_at'], $validated['approved_by']);

        $company->update($validated);

        return response()->json([
            'message' => 'Company profile updated successfully.',
            'company' => $company,
        ]);
    }

    /**
     * List company's own jobs.
     */
    public function jobs(Request $request)
    {
        $company = $request->user()->company;

        $query = PlacementOpportunity::forCompany($company->id)
            ->withCount(['applications', 'interviews']);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $jobs = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($jobs);
    }

    /**
     * Store new job by company (defaults to pending_approval).
     */
    public function storeJob(Request $request)
    {
        $company = $request->user()->company;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'employment_type' => 'required|string|max:50',
            'work_mode' => 'nullable|string|max:50',
            'location' => 'required|string|max:255',
            'salary_package' => 'nullable|string|max:100',
            'experience_required' => 'nullable|string|max:100',
            'eligibility' => 'nullable|string|max:1000',
            'minimum_qualification' => 'nullable|string|max:255',
            'skills_required' => 'required|array',
            'preferred_skills' => 'nullable|array',
            'description' => 'required|string',
            'selection_process' => 'nullable|string',
            'additional_requirements' => 'nullable|string',
            'openings_count' => 'nullable|integer|min:1',
            'deadline_date' => 'nullable|date',
            'status' => 'nullable|string|in:draft,pending_approval',
        ]);

        $status = $validated['status'] ?? PlacementOpportunity::STATUS_PENDING_APPROVAL;

        $job = PlacementOpportunity::create([
            'company_id' => $company->id,
            'title' => $validated['title'],
            'company_name' => $company->name,
            'company_logo' => $company->logo,
            'employment_type' => $validated['employment_type'],
            'work_mode' => $validated['work_mode'] ?? 'On-site',
            'location' => $validated['location'],
            'salary_package' => $validated['salary_package'] ?? null,
            'experience_required' => $validated['experience_required'] ?? null,
            'eligibility' => $validated['eligibility'] ?? null,
            'minimum_qualification' => $validated['minimum_qualification'] ?? null,
            'skills_required' => $validated['skills_required'],
            'preferred_skills' => $validated['preferred_skills'] ?? null,
            'description' => $validated['description'],
            'selection_process' => $validated['selection_process'] ?? null,
            'additional_requirements' => $validated['additional_requirements'] ?? null,
            'openings_count' => $validated['openings_count'] ?? 1,
            'deadline_date' => $validated['deadline_date'] ?? null,
            'status' => $status,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::log('company_job_created', $job, null, [
            'company_id' => $company->id,
            'job_title' => $job->title,
            'status' => $job->status,
        ]);

        return response()->json([
            'message' => $status === PlacementOpportunity::STATUS_PENDING_APPROVAL
                ? "Job '{$job->title}' submitted for MasterInTech admin review and approval."
                : "Job draft saved successfully.",
            'job' => $job,
        ], 201);
    }

    /**
     * Show single job with authorization check.
     */
    public function showJob(Request $request, PlacementOpportunity $job)
    {
        $company = $request->user()->company;

        if ($job->company_id !== $company->id) {
            return response()->json(['message' => 'Unauthorized access to job posting.'], 403);
        }

        $job->loadCount(['applications', 'interviews']);
        return response()->json($job);
    }

    /**
     * Update job with authorization check.
     */
    public function updateJob(Request $request, PlacementOpportunity $job)
    {
        $company = $request->user()->company;

        if ($job->company_id !== $company->id) {
            return response()->json(['message' => 'Unauthorized access to job posting.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'employment_type' => 'sometimes|required|string|max:50',
            'work_mode' => 'nullable|string|max:50',
            'location' => 'sometimes|required|string|max:255',
            'salary_package' => 'nullable|string|max:100',
            'experience_required' => 'nullable|string|max:100',
            'eligibility' => 'nullable|string|max:1000',
            'minimum_qualification' => 'nullable|string|max:255',
            'skills_required' => 'sometimes|required|array',
            'preferred_skills' => 'nullable|array',
            'description' => 'sometimes|required|string',
            'selection_process' => 'nullable|string',
            'additional_requirements' => 'nullable|string',
            'openings_count' => 'nullable|integer|min:1',
            'deadline_date' => 'nullable|date',
            'status' => 'nullable|string|in:draft,pending_approval,closed',
        ]);

        $job->update($validated);

        return response()->json([
            'message' => 'Job posting updated successfully.',
            'job' => $job,
        ]);
    }

    /**
     * Delete job with authorization check.
     */
    public function destroyJob(Request $request, PlacementOpportunity $job)
    {
        $company = $request->user()->company;

        if ($job->company_id !== $company->id) {
            return response()->json(['message' => 'Unauthorized access to job posting.'], 403);
        }

        $title = $job->title;
        $job->delete();

        return response()->json([
            'message' => "Job '{$title}' deleted successfully.",
        ]);
    }

    /**
     * List applications for this company's jobs only.
     */
    public function applications(Request $request)
    {
        $company = $request->user()->company;

        $query = PlacementApplication::forCompany($company->id)
            ->with([
                'opportunity:id,title,location,employment_type,salary_package,status',
                'batch:id,name,code,start_date',
                'course:id,title,code',
                'interviews' => function ($q) {
                    $q->orderBy('interview_date', 'desc');
                },
            ]);

        if ($request->filled('placement_opportunity_id') && $request->placement_opportunity_id !== 'all') {
            $query->where('placement_opportunity_id', (int) $request->placement_opportunity_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->forStatus($request->status);
        }

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $applications = $query->orderBy('applied_at', 'desc')
            ->paginate($request->input('per_page', 25));

        return response()->json($applications);
    }

    /**
     * Update application status (Shortlist, Under Review, Select, Reject).
     */
    public function updateApplicationStatus(Request $request, PlacementApplication $application)
    {
        $company = $request->user()->company;

        if ($application->opportunity->company_id !== $company->id) {
            return response()->json(['message' => 'Unauthorized access to candidate application.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:under_review,shortlisted,interview_scheduled,selected,rejected',
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $validated['reviewed_by'] = $request->user()->id;

        $application->update($validated);

        AuditLog::log('company_updated_application_status', $application, null, [
            'company_id' => $company->id,
            'new_status' => $validated['status'],
        ]);

        return response()->json([
            'message' => "Candidate status updated to " . strtoupper(str_replace('_', ' ', $validated['status'])) . ".",
            'application' => $application->load(['opportunity', 'batch', 'course', 'interviews']),
        ]);
    }

    /**
     * List scheduled interviews for company.
     */
    public function interviews(Request $request)
    {
        $company = $request->user()->company;

        $query = PlacementInterview::forCompany($company->id)
            ->with([
                'opportunity:id,title',
                'candidate:id,name,email,student_id',
                'application:id,batch_code,course_title,status,resume_url',
            ]);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $interviews = $query->orderBy('interview_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return response()->json($interviews);
    }

    /**
     * Schedule an interview with candidate.
     */
    public function scheduleInterview(Request $request)
    {
        $company = $request->user()->company;

        $validated = $request->validate([
            'placement_application_id' => 'required|exists:placement_applications,id',
            'interview_date' => 'required|date',
            'interview_type' => 'required|string|in:online,offline,phone',
            'meeting_link' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:2000',
        ]);

        $application = PlacementApplication::with('opportunity')->findOrFail($validated['placement_application_id']);

        if ($application->opportunity->company_id !== $company->id) {
            return response()->json(['message' => 'Unauthorized access to application.'], 403);
        }

        $interview = PlacementInterview::create([
            'placement_application_id' => $application->id,
            'placement_opportunity_id' => $application->placement_opportunity_id,
            'company_id' => $company->id,
            'candidate_id' => $application->user_id,
            'interview_date' => $validated['interview_date'],
            'interview_type' => $validated['interview_type'],
            'meeting_link' => $validated['meeting_link'] ?? null,
            'location' => $validated['location'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'status' => PlacementInterview::STATUS_SCHEDULED,
            'conducted_by' => $request->user()->id,
        ]);

        // Synchronize application status & interview details
        $application->update([
            'status' => PlacementApplication::STATUS_INTERVIEW_SCHEDULED,
            'interview_date' => $validated['interview_date'],
            'interview_notes' => ($validated['meeting_link'] ? "Meeting Link: {$validated['meeting_link']} " : '') . ($validated['instructions'] ?? ''),
        ]);

        AuditLog::log('company_scheduled_interview', $interview, null, [
            'company_id' => $company->id,
            'candidate_id' => $application->user_id,
            'interview_date' => $validated['interview_date'],
        ]);

        return response()->json([
            'message' => 'Interview scheduled successfully. Candidate has been notified.',
            'interview' => $interview->load(['opportunity', 'candidate', 'application']),
        ], 201);
    }

    /**
     * Submit interview evaluation and scorecard.
     */
    public function submitFeedback(Request $request, PlacementInterview $interview)
    {
        $company = $request->user()->company;

        if ($interview->company_id !== $company->id) {
            return response()->json(['message' => 'Unauthorized access to interview record.'], 403);
        }

        $validated = $request->validate([
            'technical_score' => 'nullable|integer|min:1|max:10',
            'communication_score' => 'nullable|integer|min:1|max:10',
            'overall_score' => 'nullable|integer|min:1|max:10',
            'feedback' => 'nullable|string|max:3000',
            'recommendation' => 'required|string|in:select,reject,further_round',
            'interviewer_notes' => 'nullable|string|max:3000',
        ]);

        $validated['status'] = PlacementInterview::STATUS_COMPLETED;

        $interview->update($validated);

        // If company recommends 'select', advance application status
        if ($validated['recommendation'] === PlacementInterview::RECOMMENDATION_SELECT) {
            $interview->application?->update(['status' => PlacementApplication::STATUS_SELECTED]);
        } elseif ($validated['recommendation'] === PlacementInterview::RECOMMENDATION_REJECT) {
            $interview->application?->update(['status' => PlacementApplication::STATUS_REJECTED]);
        }

        return response()->json([
            'message' => 'Interview evaluation and scorecard submitted successfully.',
            'interview' => $interview->load(['opportunity', 'candidate', 'application']),
        ]);
    }
}
