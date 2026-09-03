<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlacementOpportunity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminCorporatePartnerController extends Controller
{
    /**
     * List all corporate partners with search and status filters.
     */
    public function partners(Request $request)
    {
        $query = Company::withCount(['opportunities', 'interviews']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $companies = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 25));

        return response()->json($companies);
    }

    /**
     * Show single company partner details.
     */
    public function showPartner(Company $company)
    {
        $company->load([
            'approver:id,name,email',
            'user:id,name,email,role,status',
            'opportunities' => function ($q) {
                $q->withCount('applications')->orderBy('created_at', 'desc');
            },
        ]);

        return response()->json($company);
    }

    /**
     * Approve company partnership and provision recruiter login credentials.
     */
    public function approvePartner(Request $request, Company $company)
    {
        $admin = $request->user();

        // 1. Check or create User for company
        $user = User::where('email', $company->email)->first();

        $defaultPassword = $request->input('password') ?: \Illuminate\Support\Str::random(16);

        if (! $user) {
            $user = User::create([
                'name' => $company->hr_name,
                'email' => $company->email,
                'phone' => $company->phone,
                'password' => Hash::make($defaultPassword),
                'role' => 'company',
                'company_id' => $company->id,
                'status' => 'active',
            ]);
        } else {
            $user->update([
                'role' => 'company',
                'company_id' => $company->id,
                'status' => 'active',
                'password' => Hash::make($defaultPassword),
            ]);
        }

        // 2. Update company status
        $company->update([
            'status' => Company::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'created_user_id' => $user->id,
            'rejection_reason' => null,
        ]);

        AuditLog::log('approved_corporate_partner', $company, null, [
            'company_name' => $company->name,
            'approved_by' => $admin->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => "Corporate Partner '{$company->name}' approved successfully! Recruiter account activated with email '{$company->email}'.",
            'company' => $company->fresh()->load('user'),
            'credentials' => [
                'email' => $company->email,
                'password' => $defaultPassword,
            ],
        ]);
    }

    /**
     * Reject company partnership request.
     */
    public function rejectPartner(Request $request, Company $company)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $company->update([
            'status' => Company::STATUS_REJECTED,
            'rejection_reason' => $validated['reason'] ?? 'Partnership request does not meet current eligibility requirements.',
        ]);

        if ($company->created_user_id) {
            User::where('id', $company->created_user_id)->update(['status' => 'inactive']);
        }

        AuditLog::log('rejected_corporate_partner', $company, null, [
            'company_name' => $company->name,
            'reason' => $company->rejection_reason,
        ]);

        return response()->json([
            'message' => "Partnership request for '{$company->name}' rejected.",
            'company' => $company,
        ]);
    }

    /**
     * Suspend company access.
     */
    public function suspendPartner(Request $request, Company $company)
    {
        $company->update([
            'status' => Company::STATUS_SUSPENDED,
        ]);

        if ($company->created_user_id) {
            $user = User::find($company->created_user_id);
            if ($user) {
                $user->update(['status' => 'suspended', 'current_session_id' => null]);
                $user->tokens()->delete();
            }
        }

        AuditLog::log('suspended_corporate_partner', $company, null, [
            'company_name' => $company->name,
        ]);

        return response()->json([
            'message' => "Corporate partner '{$company->name}' has been suspended.",
            'company' => $company,
        ]);
    }

    /**
     * Reactivate suspended company.
     */
    public function reactivatePartner(Request $request, Company $company)
    {
        $company->update([
            'status' => Company::STATUS_APPROVED,
        ]);

        if ($company->created_user_id) {
            User::where('id', $company->created_user_id)->update(['status' => 'active']);
        }

        AuditLog::log('reactivated_corporate_partner', $company, null, [
            'company_name' => $company->name,
        ]);

        return response()->json([
            'message' => "Corporate partner '{$company->name}' reactivated successfully.",
            'company' => $company,
        ]);
    }

    /**
     * List company jobs pending admin approval.
     */
    public function pendingJobs(Request $request)
    {
        $jobs = PlacementOpportunity::with(['company:id,name,logo,industry,location'])
            ->where('status', PlacementOpportunity::STATUS_PENDING_APPROVAL)
            ->orderBy('created_at', 'asc')
            ->paginate($request->input('per_page', 20));

        return response()->json($jobs);
    }

    /**
     * Approve company job and publish live to Placement Portal.
     */
    public function approveJob(Request $request, PlacementOpportunity $job)
    {
        $job->update([
            'status' => PlacementOpportunity::STATUS_PUBLISHED,
        ]);

        AuditLog::log('approved_company_job', $job, null, [
            'job_id' => $job->id,
            'job_title' => $job->title,
            'company_name' => $job->company_name,
        ]);

        return response()->json([
            'message' => "Job '{$job->title}' approved and published live to Placement Portal.",
            'job' => $job->fresh(),
        ]);
    }

    /**
     * Reject company job posting.
     */
    public function rejectJob(Request $request, PlacementOpportunity $job)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $job->update([
            'status' => PlacementOpportunity::STATUS_REJECTED,
        ]);

        AuditLog::log('rejected_company_job', $job, null, [
            'job_id' => $job->id,
            'job_title' => $job->title,
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'message' => "Job '{$job->title}' rejected.",
            'job' => $job->fresh(),
        ]);
    }
}
