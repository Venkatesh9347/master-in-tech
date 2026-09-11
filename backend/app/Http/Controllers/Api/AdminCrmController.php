<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CrmActivity;
use App\Models\CrmFollowUp;
use App\Models\Enquiry;
use App\Models\User;
use App\Services\EnrollmentAssignmentService;
use App\Services\BatchAssignmentException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCrmController extends Controller
{
    public function __construct(private readonly EnrollmentAssignmentService $enrollments)
    {
    }

    /**
     * Get aggregated CRM Dashboard KPI Metrics.
     * Counsellors receive scoped metrics (own + unassigned leads only).
     */
    public function stats(Request $request)
    {
        $today = Carbon::today();
        $user = $request->user();
        $leads = fn () => Enquiry::visibleTo($user);
        $followUps = fn () => CrmFollowUp::whereHas('enquiry', fn ($q) => $q->visibleTo($user));

        $totalLeads = $leads()->count();
        $newLeads = $leads()->where('status', Enquiry::STATUS_NEW)->count();
        $contacted = $leads()->where('status', Enquiry::STATUS_CONTACTED)->count();
        $interested = $leads()->where('status', Enquiry::STATUS_INTERESTED)->count();
        $demos = $leads()->whereIn('status', [Enquiry::STATUS_DEMO_SCHEDULED, Enquiry::STATUS_DEMO_COMPLETED])->count();
        $paymentPending = $leads()->where('status', Enquiry::STATUS_PAYMENT_PENDING)->count();
        $converted = $leads()->whereIn('status', [Enquiry::STATUS_CONVERTED, Enquiry::STATUS_ENROLLED, Enquiry::STATUS_ADMISSION_CONFIRMED])->count();
        $lost = $leads()->whereIn('status', [Enquiry::STATUS_LOST, Enquiry::STATUS_NOT_INTERESTED, Enquiry::STATUS_CLOSED])->count();

        // Follow-up KPI breakdown
        $overdueFollowUps = $followUps()->pending()->where('scheduled_at', '<', $today)->count();
        $todaysFollowUps = $followUps()->pending()->whereDate('scheduled_at', $today)->count();
        $upcomingFollowUps = $followUps()->pending()->where('scheduled_at', '>', Carbon::tomorrow()->startOfDay())->count();
        $completedFollowUps = $followUps()->where('status', CrmFollowUp::STATUS_COMPLETED)->count();

        // Follow-ups due total (overdue + today)
        $followUpsDue = $overdueFollowUps + $todaysFollowUps;

        // Conversion Rate calculation
        $conversionRate = $totalLeads > 0 ? round(($converted / $totalLeads) * 100, 1) : 0.0;

        // Source breakdown
        $sourceBreakdown = $leads()->select('source', DB::raw('count(*) as total'))
            ->groupBy('source')
            ->pluck('total', 'source');

        // Priority breakdown
        $priorityBreakdown = $leads()->select('priority', DB::raw('count(*) as total'))
            ->groupBy('priority')
            ->pluck('total', 'priority');

        return response()->json([
            'total_leads' => $totalLeads,
            'new_leads' => $newLeads,
            'contacted' => $contacted,
            'follow_ups_due' => $followUpsDue,
            'demos' => $demos,
            'interested' => $interested,
            'payment_pending' => $paymentPending,
            'converted' => $converted,
            'lost' => $lost,
            'overdue_follow_ups' => $overdueFollowUps,
            'todays_follow_ups' => $todaysFollowUps,
            'upcoming_follow_ups' => $upcomingFollowUps,
            'completed_follow_ups' => $completedFollowUps,
            'conversion_rate' => $conversionRate,
            'source_breakdown' => $sourceBreakdown,
            'priority_breakdown' => $priorityBreakdown,
        ]);
    }

    /**
     * List all leads with filters, search, and eager loading.
     */
    public function index(Request $request)
    {
        $query = Enquiry::with([
            'course:id,title,code,category,price,slug,thumbnail',
            'assignedCounsellor:id,name,email,avatar,role',
            'user:id,name,email,student_id,avatar',
            'enrolledUser:id,name,email,student_id',
            'latestFollowUp',
        ])->withCount(['activities', 'followUps']);

        // Record-level scoping: counsellors only ever list own + unassigned.
        // An explicit assigned_counsellor_id filter for another counsellor
        // simply yields an empty page — never a leak.
        $query->visibleTo($request->user());

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->forStatus($request->status);
        }

        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->forPriority($request->priority);
        }

        if ($request->filled('source') && $request->source !== 'all') {
            $query->forSource($request->source);
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', (int) $request->course_id);
        }

        if ($request->filled('assigned_counsellor_id') && $request->assigned_counsellor_id !== 'all') {
            $query->forCounsellor((int) $request->assigned_counsellor_id);
        }

        // Follow-up status filter on leads
        if ($request->filled('follow_up_filter')) {
            $filter = $request->follow_up_filter;
            $today = Carbon::today();
            if ($filter === 'overdue') {
                $query->whereHas('followUps', function ($q) use ($today) {
                    $q->where('status', 'pending')->where('scheduled_at', '<', $today);
                });
            } elseif ($filter === 'today') {
                $query->whereHas('followUps', function ($q) use ($today) {
                    $q->where('status', 'pending')->whereDate('scheduled_at', $today);
                });
            } elseif ($filter === 'upcoming') {
                $query->whereHas('followUps', function ($q) use ($today) {
                    $q->where('status', 'pending')->where('scheduled_at', '>', Carbon::tomorrow()->startOfDay());
                });
            }
        }

        $leads = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json($leads);
    }

    /**
     * Store a new lead manually in CRM.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:30',
            'course_id' => 'nullable|exists:courses,id',
            'course_title' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:50',
            'priority' => 'nullable|string|in:hot,warm,cold,high,medium,low',
            'assigned_counsellor_id' => 'nullable|exists:users,id',
            'preferred_time' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:2000',
            'qualification' => 'nullable|string|max:255',
            'experience_level' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'expected_revenue' => 'nullable|numeric|min:0',
            'status' => 'nullable|string',
            'next_follow_up_date' => 'nullable|date',
            'next_follow_up_time' => 'nullable|string|max:50',
        ]);

        $email = strtolower(trim($validated['email']));
        $phone = trim($validated['phone']);
        $courseId = isset($validated['course_id']) ? (int) $validated['course_id'] : null;

        // Check if an active lead already exists with the same email and course
        $existing = Enquiry::where('email', $email)
            ->where(function ($q) use ($courseId) {
                if ($courseId) {
                    $q->where('course_id', $courseId);
                }
            })
            ->whereNotIn('status', [Enquiry::STATUS_CLOSED, Enquiry::STATUS_NOT_INTERESTED, Enquiry::STATUS_LOST])
            ->first();

        if ($existing) {
            $response = [
                'message' => 'An active lead already exists for this candidate.',
                'already_exists' => true,
            ];
            // Never leak another counsellor's lead through duplicate probing.
            if ($existing->isVisibleTo($request->user())) {
                $response['lead'] = $existing->load(['course', 'assignedCounsellor', 'latestFollowUp']);
            }
            return response()->json($response, 422);
        }

        $courseTitle = $validated['course_title'] ?? null;
        if ($courseId && empty($courseTitle)) {
            $courseTitle = Course::find($courseId)?->title;
        }

        $counsellorId = $validated['assigned_counsellor_id'] ?? null;
        $this->denyUnlessReassignAllowed($request, null, $counsellorId);
        $counsellorName = $counsellorId ? User::find($counsellorId)?->name : ($request->user()->name ?? 'Administrator');

        $lead = Enquiry::create([
            'name' => trim($validated['name']),
            'email' => $email,
            'phone' => $phone,
            'source' => $validated['source'] ?? 'website',
            'priority' => $validated['priority'] ?? 'medium',
            'course_id' => $courseId,
            'course_title' => $courseTitle,
            'preferred_time' => $validated['preferred_time'] ?? null,
            'message' => $validated['message'] ?? null,
            'qualification' => $validated['qualification'] ?? null,
            'experience_level' => $validated['experience_level'] ?? null,
            'city' => $validated['city'] ?? null,
            'expected_revenue' => $validated['expected_revenue'] ?? null,
            'status' => $validated['status'] ?? Enquiry::STATUS_NEW,
            'assigned_counsellor_id' => $counsellorId,
            'assigned_agent' => $counsellorName,
            'next_follow_up_date' => $validated['next_follow_up_date'] ?? null,
            'next_follow_up_time' => $validated['next_follow_up_time'] ?? null,
        ]);

        // 1. Log initial timeline activity
        CrmActivity::create([
            'enquiry_id' => $lead->id,
            'user_id' => $request->user()->id,
            'activity_type' => 'note',
            'title' => 'Lead created in CRM',
            'description' => "Lead manually created by {$request->user()->name}. Source: " . ($lead->source ?? 'Direct'),
            'metadata' => [
                'created_by' => $request->user()->id,
                'source' => $lead->source,
                'priority' => $lead->priority,
            ],
        ]);

        // 2. If next follow-up date provided, schedule initial follow-up task
        if (! empty($validated['next_follow_up_date'])) {
            $followUpDateTime = Carbon::parse($validated['next_follow_up_date']);
            if (! empty($validated['next_follow_up_time'])) {
                try {
                    $time = Carbon::parse($validated['next_follow_up_time']);
                    $followUpDateTime->setTime($time->hour, $time->minute);
                } catch (\Exception $e) {
                    $followUpDateTime->setTime(10, 0);
                }
            }

            CrmFollowUp::create([
                'enquiry_id' => $lead->id,
                'assigned_to' => $counsellorId ?? $request->user()->id,
                'created_by' => $request->user()->id,
                'scheduled_at' => $followUpDateTime,
                'status' => CrmFollowUp::STATUS_PENDING,
                'title' => 'Initial follow-up call with prospective candidate',
                'notes' => 'Introduce MasterInTech program details and ascertain candidate goals.',
            ]);
        }

        $lead->load([
            'course:id,title,code,category,price,slug,thumbnail',
            'assignedCounsellor:id,name,email,avatar,role',
            'activities.user:id,name,email',
            'followUps.assignedTo:id,name,email',
            'latestFollowUp',
        ]);

        AuditLog::log('created_crm_lead', $lead, null, $lead->toArray());

        return response()->json([
            'message' => "Lead for {$lead->name} created successfully.",
            'lead' => $lead,
        ], 201);
    }

    /**
     * Show single lead details with full timeline and follow-ups.
     */
    public function show(Request $request, Enquiry $lead)
    {
        $this->denyUnlessLeadVisible($request, $lead);
        $lead->load([
            'course:id,title,code,category,price,slug,thumbnail,instructor',
            'assignedCounsellor:id,name,email,avatar,role,phone',
            'user:id,name,email,student_id,avatar,status',
            'enrolledUser:id,name,email,student_id,avatar,status',
            'activities.user:id,name,email,avatar',
            'followUps.assignedTo:id,name,email',
            'followUps.createdBy:id,name,email',
            'latestFollowUp',
        ]);

        return response()->json($lead);
    }

    /**
     * Update lead details.
     */
    public function update(Request $request, Enquiry $lead)
    {
        $this->denyUnlessLeadVisible($request, $lead);
        $old = $lead->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'sometimes|required|string|max:30',
            'source' => 'nullable|string|max:50',
            'priority' => 'nullable|string|in:hot,warm,cold,high,medium,low',
            'course_id' => 'nullable|exists:courses,id',
            'course_title' => 'nullable|string|max:255',
            'status' => 'nullable|string',
            'assigned_counsellor_id' => 'nullable|exists:users,id',
            'preferred_time' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:2000',
            'qualification' => 'nullable|string|max:255',
            'experience_level' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'expected_revenue' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string|in:unpaid,partial,paid',
            'lost_reason' => 'nullable|string|max:1000',
            'next_follow_up_date' => 'nullable|date',
            'next_follow_up_time' => 'nullable|string|max:50',
            'demo_date' => 'nullable|date',
            'demo_time' => 'nullable|string|max:50',
            'demo_outcome' => 'nullable|string|max:255',
        ]);

        $oldStatus = $lead->status;
        $oldPriority = $lead->priority;
        $oldCounsellorId = $lead->assigned_counsellor_id;

        if (array_key_exists('assigned_counsellor_id', $validated)) {
            $this->denyUnlessReassignAllowed($request, $lead, $validated['assigned_counsellor_id']);
        }

        // If course changed, update title
        if (! empty($validated['course_id']) && $validated['course_id'] != $lead->course_id) {
            $course = Course::find($validated['course_id']);
            if ($course) {
                $validated['course_title'] = $course->title;
            }
        }

        // If counsellor changed, update name
        if (array_key_exists('assigned_counsellor_id', $validated)) {
            $counsellorId = $validated['assigned_counsellor_id'];
            $validated['assigned_agent'] = $counsellorId ? User::find($counsellorId)?->name : null;
        }

        $lead->update($validated);

        // Timeline activity log for status change
        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $newStatusLabel = strtoupper(str_replace('_', ' ', $validated['status']));
            $oldStatusLabel = strtoupper(str_replace('_', ' ', $oldStatus));
            CrmActivity::create([
                'enquiry_id' => $lead->id,
                'user_id' => $request->user()->id,
                'activity_type' => 'status_change',
                'title' => "Status changed to {$newStatusLabel}",
                'description' => "Pipeline status transitioned from {$oldStatusLabel} to {$newStatusLabel}.",
                'metadata' => [
                    'old_status' => $oldStatus,
                    'new_status' => $validated['status'],
                    'updated_by' => $request->user()->name,
                ],
            ]);
        }

        // Timeline activity log for counsellor reassignment
        if (array_key_exists('assigned_counsellor_id', $validated) && $validated['assigned_counsellor_id'] != $oldCounsellorId) {
            $newCounsellor = $validated['assigned_counsellor_id'] ? User::find($validated['assigned_counsellor_id'])?->name : 'Unassigned';
            CrmActivity::create([
                'enquiry_id' => $lead->id,
                'user_id' => $request->user()->id,
                'activity_type' => 'note',
                'title' => "Assigned to {$newCounsellor}",
                'description' => "Counsellor ownership updated to {$newCounsellor} by {$request->user()->name}.",
            ]);
        }

        $lead->load([
            'course:id,title,code,category,price,slug,thumbnail',
            'assignedCounsellor:id,name,email,avatar,role',
            'activities.user:id,name,email,avatar',
            'followUps.assignedTo:id,name,email',
            'latestFollowUp',
        ]);

        AuditLog::log('updated_crm_lead', $lead, $old, $lead->toArray());

        return response()->json([
            'message' => 'Lead updated successfully.',
            'lead' => $lead,
        ]);
    }

    /**
     * Delete lead.
     */
    public function destroy(Request $request, Enquiry $lead)
    {
        $this->denyUnlessLeadVisible($request, $lead);
        $old = $lead->toArray();
        $name = $lead->name;
        $lead->delete();

        AuditLog::log('deleted_crm_lead', null, $old, null);

        return response()->json([
            'message' => "Lead {$name} deleted successfully.",
        ]);
    }

    /**
     * Log an activity on the lead timeline (note, call, demo, payment_event).
     */
    public function activities(Request $request, Enquiry $lead)
    {
        $this->denyUnlessLeadVisible($request, $lead);
        $validated = $request->validate([
            'activity_type' => 'required|string|in:note,call,follow_up,demo_scheduled,demo_completed,payment_event,status_change',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:3000',
            'metadata' => 'nullable|array',
        ]);

        $activity = CrmActivity::create([
            'enquiry_id' => $lead->id,
            'user_id' => $request->user()->id,
            'activity_type' => $validated['activity_type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        // If activity is a payment event, update lead amount_paid & payment_status
        if ($validated['activity_type'] === 'payment_event' && isset($validated['metadata']['amount'])) {
            $amount = (float) $validated['metadata']['amount'];
            $newTotal = (float) $lead->amount_paid + $amount;
            $lead->amount_paid = $newTotal;
            if ($lead->expected_revenue && $newTotal >= (float) $lead->expected_revenue) {
                $lead->payment_status = 'paid';
            } elseif ($newTotal > 0) {
                $lead->payment_status = 'partial';
            }
            $lead->save();
        }

        $activity->load('user:id,name,email,avatar');

        return response()->json([
            'message' => 'Activity logged on timeline successfully.',
            'activity' => $activity,
            'lead' => $lead->fresh(['course', 'assignedCounsellor', 'activities.user', 'followUps.assignedTo']),
        ], 201);
    }

    /**
     * List follow-ups across leads (with overdue, today, upcoming, completed filters).
     */
    public function followUps(Request $request)
    {
        $query = CrmFollowUp::with([
            'enquiry:id,name,email,phone,course_id,course_title,status,priority,city',
            'assignedTo:id,name,email,avatar',
            'createdBy:id,name,email',
        ])->whereHas('enquiry', fn ($q) => $q->visibleTo($request->user()));

        $today = Carbon::today();
        $filter = $request->input('filter', 'pending');

        if ($filter === 'overdue') {
            $query->pending()->where('scheduled_at', '<', $today);
        } elseif ($filter === 'today') {
            $query->pending()->whereDate('scheduled_at', $today);
        } elseif ($filter === 'upcoming') {
            $query->pending()->where('scheduled_at', '>', Carbon::tomorrow()->startOfDay());
        } elseif ($filter === 'completed') {
            $query->where('status', CrmFollowUp::STATUS_COMPLETED);
        } elseif ($filter === 'pending') {
            $query->pending();
        }

        if ($request->filled('assigned_to') && $request->assigned_to !== 'all') {
            $query->where('assigned_to', (int) $request->assigned_to);
        }

        $followUps = $query->orderBy('scheduled_at', 'asc')->get();

        return response()->json($followUps);
    }

    /**
     * Store a scheduled follow-up for a lead.
     */
    public function storeFollowUp(Request $request, Enquiry $lead)
    {
        $this->denyUnlessLeadVisible($request, $lead);
        $validated = $request->validate([
            'scheduled_at' => 'required|date',
            'title' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $assignedToId = $validated['assigned_to'] ?? $lead->assigned_counsellor_id ?? $request->user()->id;

        $followUp = CrmFollowUp::create([
            'enquiry_id' => $lead->id,
            'assigned_to' => $assignedToId,
            'created_by' => $request->user()->id,
            'scheduled_at' => Carbon::parse($validated['scheduled_at']),
            'status' => CrmFollowUp::STATUS_PENDING,
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
        ]);

        // Sync lead's next follow up date
        $lead->update([
            'next_follow_up_date' => Carbon::parse($validated['scheduled_at'])->toDateString(),
            'next_follow_up_time' => Carbon::parse($validated['scheduled_at'])->format('h:i A'),
            'status' => $lead->status === Enquiry::STATUS_NEW ? Enquiry::STATUS_FOLLOW_UP : $lead->status,
        ]);

        // Log timeline activity
        CrmActivity::create([
            'enquiry_id' => $lead->id,
            'user_id' => $request->user()->id,
            'activity_type' => 'follow_up',
            'title' => "Follow-up scheduled: {$validated['title']}",
            'description' => "Scheduled for " . Carbon::parse($validated['scheduled_at'])->toDayDateTimeString() . ". " . ($validated['notes'] ?? ''),
            'metadata' => [
                'follow_up_id' => $followUp->id,
                'scheduled_at' => $followUp->scheduled_at->toISOString(),
            ],
        ]);

        $followUp->load(['assignedTo:id,name,email', 'createdBy:id,name,email']);

        return response()->json([
            'message' => 'Follow-up scheduled successfully.',
            'follow_up' => $followUp,
            'lead' => $lead->fresh(['course', 'assignedCounsellor', 'activities.user', 'followUps.assignedTo']),
        ], 201);
    }

    /**
     * Update follow-up (e.g. mark as completed or cancelled with outcome notes).
     */
    public function updateFollowUp(Request $request, CrmFollowUp $followUp)
    {
        if ($followUp->enquiry && ! $followUp->enquiry->isVisibleTo($request->user())) {
            abort(403, 'You do not have access to this lead.');
        }
        $validated = $request->validate([
            'status' => 'required|string|in:completed,cancelled,pending',
            'outcome' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
        ]);

        $completedAt = $validated['status'] === 'completed' ? now() : null;

        $followUp->update([
            'status' => $validated['status'],
            'outcome' => $validated['outcome'] ?? $followUp->outcome,
            'notes' => $validated['notes'] ?? $followUp->notes,
            'completed_at' => $completedAt,
        ]);

        $lead = $followUp->enquiry;

        // Log activity
        if ($validated['status'] === 'completed') {
            CrmActivity::create([
                'enquiry_id' => $lead->id,
                'user_id' => $request->user()->id,
                'activity_type' => 'follow_up',
                'title' => "Completed follow-up: {$followUp->title}",
                'description' => "Outcome: " . ($validated['outcome'] ?? 'Follow-up completed successfully.'),
                'metadata' => [
                    'follow_up_id' => $followUp->id,
                    'outcome' => $validated['outcome'] ?? null,
                ],
            ]);
        }

        return response()->json([
            'message' => "Follow-up marked as {$validated['status']}.",
            'follow_up' => $followUp->fresh(['assignedTo', 'createdBy', 'enquiry']),
        ]);
    }

    /**
     * CRM → LMS Conversion Engine:
     * Converts a lead to a student, prevents duplicate user accounts,
     * assigns Course & active Cohort Batch, activates LMS access, logs timeline activity and AuditLog.
     */
    public function convert(Request $request, Enquiry $lead)
    {
        $this->denyUnlessLeadVisible($request, $lead);
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_mode' => 'nullable|string|max:50', // card, upi, netbanking, cash, bank_transfer
            'transaction_id' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        $studentEmail = strtolower(trim($validated['email'] ?? $lead->email));
        $studentName = trim($validated['name'] ?? $lead->name);
        $studentPhone = trim($validated['phone'] ?? $lead->phone);
        $courseId = (int) $validated['course_id'];
        $course = Course::findOrFail($courseId);

        $batchId = ! empty($validated['batch_id']) ? (int) $validated['batch_id'] : null;
        $batch = null;
        if ($batchId) {
            $batch = Batch::find($batchId);
            if (! $batch) {
                return response()->json([
                    'message' => 'The selected cohort batch does not exist.',
                ], 422);
            }
            // Enrollment/batch consistency: a cohort batch must belong to the enrolled course.
            if ($batch->course_id !== $course->id) {
                return response()->json([
                    'message' => "The selected cohort batch ({$batch->code}) belongs to a different course. Please choose a batch for '{$course->title}'.",
                ], 422);
            }
        }

        $amountPaid = isset($validated['amount_paid']) ? (float) $validated['amount_paid'] : (float) $lead->amount_paid;

        DB::beginTransaction();
        try {
            // 1. Find or Provision Student User Account (Prevent duplicate student accounts)
            $user = $this->enrollments->ensureStudentUser($studentName, $studentEmail, $studentPhone);

            // 2. Ensure Course Enrollment with active LMS access
            $enrollment = $this->enrollments->ensureActiveEnrollment($user, $course);

            // 3. If Batch is selected, assign student to the cohort batch
            $batchMembership = null;
            if ($batch) {
                $batchMembership = $this->enrollments->assignToBatch(
                    $user,
                    $batch,
                    performedBy: $request->user()->id,
                    reason: 'Direct CRM lead conversion to cohort',
                    notes: 'Admitted & enrolled from CRM conversion',
                );
            }

            // 4. Update Lead to CONVERTED
            $lead->update([
                'status' => Enquiry::STATUS_CONVERTED,
                'course_id' => $course->id,
                'course_title' => $course->title,
                'enrolled_user_id' => $user->id,
                'enrolled_at' => now(),
                'amount_paid' => $amountPaid,
                'payment_status' => $amountPaid > 0 ? ($lead->expected_revenue && $amountPaid >= (float) $lead->expected_revenue ? 'paid' : 'partial') : 'unpaid',
            ]);

            // 5. Log Timeline Conversion Activity
            $batchCodeText = $batch ? " and cohort batch {$batch->code}" : '';
            CrmActivity::create([
                'enquiry_id' => $lead->id,
                'user_id' => $request->user()->id,
                'activity_type' => 'conversion',
                'title' => "Converted to Enrolled Student ({$user->student_id})",
                'description' => "Successfully admitted to '{$course->title}'{$batchCodeText}. LMS classroom access activated.",
                'metadata' => [
                    'user_id' => $user->id,
                    'student_id' => $user->student_id,
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                    'batch_id' => $batch?->id,
                    'batch_code' => $batch?->code,
                    'amount_paid' => $amountPaid,
                    'payment_mode' => $validated['payment_mode'] ?? null,
                    'transaction_id' => $validated['transaction_id'] ?? null,
                ],
            ]);

            // If payment was made at conversion, log payment event
            if ($amountPaid > 0) {
                CrmActivity::create([
                    'enquiry_id' => $lead->id,
                    'user_id' => $request->user()->id,
                    'activity_type' => 'payment_event',
                    'title' => "Payment received: $" . number_format($amountPaid, 2),
                    'description' => "Payment recorded at admission conversion. Mode: " . ($validated['payment_mode'] ?? 'Standard') . ($validated['transaction_id'] ? " (Txn: {$validated['transaction_id']})" : ''),
                    'metadata' => [
                        'amount' => $amountPaid,
                        'mode' => $validated['payment_mode'] ?? null,
                        'transaction_id' => $validated['transaction_id'] ?? null,
                    ],
                ]);
            }

            AuditLog::log('converted_crm_lead_to_student', $lead, null, [
                'lead_id' => $lead->id,
                'student_id' => $user->student_id,
                'course_id' => $course->id,
                'batch_id' => $batch?->id,
            ]);

            DB::commit();

            return response()->json([
                'message' => "Candidate {$user->name} has been successfully converted to an active student in {$course->title}!",
                'user' => $user,
                'enrollment' => $enrollment,
                'batch' => $batch,
                'lead' => $lead->fresh(['course', 'assignedCounsellor', 'user', 'enrolledUser', 'activities.user']),
            ], 200);
        } catch (BatchAssignmentException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to convert lead to student: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get counsellors and administrators available for lead assignment.
     */
    public function counsellors()
    {
        $counsellors = User::whereIn('role', ['admin', 'super_admin', 'counsellor'])
            ->select('id', 'name', 'email', 'role', 'avatar', 'phone')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json($counsellors);
    }

    /**
     * Record-level gate: counsellors may only touch own + unassigned leads.
     */
    private function denyUnlessLeadVisible(Request $request, Enquiry $lead): void
    {
        if (! $lead->isVisibleTo($request->user())) {
            abort(403, 'You do not have access to this lead.');
        }
    }

    /**
     * Ownership guard for (re)assignment: counsellors may claim unassigned
     * leads for themselves (or release their own back to the pool) but may
     * never assign leads to other counsellors. Admins are unrestricted.
     */
    private function denyUnlessReassignAllowed(Request $request, ?Enquiry $lead, mixed $targetId): void
    {
        $user = $request->user();
        if (! $user || $user->role !== 'counsellor') {
            return;
        }

        $targetOk = $targetId === null || (int) $targetId === (int) $user->id;
        $sourceOk = $lead === null
            || $lead->assigned_counsellor_id === null
            || (int) $lead->assigned_counsellor_id === (int) $user->id;

        if (! ($targetOk && $sourceOk)) {
            abort(403, 'Counsellors can only claim unassigned leads for themselves.');
        }
    }
}
