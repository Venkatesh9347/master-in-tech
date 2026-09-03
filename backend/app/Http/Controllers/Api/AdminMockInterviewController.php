<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\MockInterview;
use App\Models\MockInterviewEvaluation;
use App\Models\MockInterviewer;
use App\Models\MockInterviewSlot;
use App\Models\StudentPlacementEligibility;
use App\Models\User;
use App\Services\MockInterviewService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminMockInterviewController extends Controller
{
    /**
     * Get Aggregated Mock Interview Metrics.
     */
    public function stats()
    {
        return response()->json(MockInterviewService::getAdminStats());
    }

    /**
     * List students with their course completion & placement eligibility breakdown.
     */
    public function eligibilityList(Request $request)
    {
        $query = User::where(function ($q) {
            $q->where('role', 'student')
                ->orWhereNull('role');
        });

        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('student_id', 'like', $term);
            });
        }

        $allStudents = $query->orderBy('name', 'asc')->get();

        $dashboardFilter = $request->input('dashboard_status', $request->input('status', 'all'));

        $items = $allStudents->map(function (User $student) {
            $eligibility = MockInterviewService::checkStudentEligibility($student);
            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'phone' => $student->phone,
                'student_id' => $student->student_id,
                'avatar' => $student->avatar,
                'eligibility' => $eligibility,
            ];
        });

        if ($dashboardFilter && $dashboardFilter !== 'all') {
            $normalizedFilter = strtoupper(trim($dashboardFilter));
            $items = $items->filter(function ($item) use ($normalizedFilter) {
                return strtoupper($item['eligibility']['placement_dashboard_status'] ?? '') === $normalizedFilter;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 50);
        $page = (int) $request->input('page', 1);
        $total = $items->count();
        $paginatedItems = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'current_page' => $page,
            'data' => $paginatedItems,
            'total' => $total,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ]);
    }

    /**
     * Set / override student placement eligibility.
     */
    public function overrideEligibility(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:users,id',
            'placement_eligible' => 'required|boolean',
            'reason' => 'required|string|max:1000',
            'notes' => 'nullable|string|max:2000',
        ]);

        $student = User::findOrFail($validated['student_id']);
        $admin = $request->user();

        $record = MockInterviewService::overridePlacementEligibility(
            $student,
            (bool) $validated['placement_eligible'],
            $admin,
            trim($validated['reason']),
            isset($validated['notes']) ? trim($validated['notes']) : null
        );

        return response()->json([
            'message' => "Placement eligibility for {$student->name} updated successfully.",
            'eligibility' => $record,
        ]);
    }

    /**
     * Admin action to enable, disable, suspend, or re-enable student's placement dashboard.
     */
    public function updateDashboardStatus(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:users,id',
            'action' => 'required|string|in:enable,disable,suspend,reenable,ENABLE,DISABLE,SUSPEND,REENABLE',
            'reason' => 'nullable|string|max:1000',
        ]);

        $student = User::findOrFail($validated['student_id']);
        $admin = $request->user();
        $action = strtolower($validated['action']);
        $reason = isset($validated['reason']) ? trim($validated['reason']) : null;

        $record = MockInterviewService::updatePlacementDashboardStatus($student, $action, $admin, $reason);

        $actionWord = match ($record->dashboard_status) {
            'ENABLED' => 'enabled',
            'SUSPENDED' => 'suspended',
            'DISABLED' => 'disabled',
            default => 'updated',
        };

        return response()->json([
            'message' => "Placement Dashboard for {$student->name} has been {$actionWord} successfully.",
            'dashboard_status' => $record->dashboard_status,
            'placement_dashboard_enabled' => $record->isDashboardEnabled(),
            'eligibility' => $record,
        ]);
    }

    /**
     * List Professional Interviewers.
     */
    public function interviewers(Request $request)
    {
        $query = MockInterviewer::withCount(['slots', 'interviews', 'evaluations']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $interviewers = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 50));

        return response()->json($interviewers);
    }

    /**
     * Create a new professional interviewer.
     */
    public function storeInterviewer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:mock_interviewers,email',
            'phone' => 'nullable|string|max:30',
            'designation' => 'required|string|max:255',
            'company' => 'required|string|max:255',
            'years_of_experience' => 'required|numeric|min:0|max:60',
            'skills' => 'required|array|min:1',
            'skills.*' => 'string|max:100',
            'bio' => 'nullable|string|max:3000',
            'internal_notes' => 'nullable|string|max:3000',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['created_by'] = $request->user()->id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $interviewer = MockInterviewer::create($validated);

        AuditLog::log('created_mock_interviewer', $interviewer, null, $interviewer->toArray());

        return response()->json([
            'message' => "Interviewer '{$interviewer->name}' created successfully.",
            'interviewer' => $interviewer,
        ], 201);
    }

    /**
     * Show single interviewer details.
     */
    public function showInterviewer(MockInterviewer $interviewer)
    {
        $interviewer->loadCount(['slots', 'interviews', 'evaluations']);
        return response()->json($interviewer);
    }

    /**
     * Update professional interviewer.
     */
    public function updateInterviewer(Request $request, MockInterviewer $interviewer)
    {
        $old = $interviewer->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:mock_interviewers,email,' . $interviewer->id,
            'phone' => 'nullable|string|max:30',
            'designation' => 'sometimes|required|string|max:255',
            'company' => 'sometimes|required|string|max:255',
            'years_of_experience' => 'sometimes|required|numeric|min:0|max:60',
            'skills' => 'sometimes|required|array|min:1',
            'skills.*' => 'string|max:100',
            'bio' => 'nullable|string|max:3000',
            'internal_notes' => 'nullable|string|max:3000',
            'is_active' => 'nullable|boolean',
        ]);

        $interviewer->update($validated);

        AuditLog::log('updated_mock_interviewer', $interviewer, $old, $interviewer->toArray());

        return response()->json([
            'message' => "Interviewer '{$interviewer->name}' updated successfully.",
            'interviewer' => $interviewer,
        ]);
    }

    /**
     * Delete or toggle professional interviewer.
     */
    public function destroyInterviewer(MockInterviewer $interviewer)
    {
        $name = $interviewer->name;
        $old = $interviewer->toArray();

        // If interviewer has existing bookings/evaluations, deactivate instead of hard delete
        if ($interviewer->interviews()->exists() || $interviewer->evaluations()->exists()) {
            $interviewer->update(['is_active' => false]);
            AuditLog::log('deactivated_mock_interviewer', $interviewer, $old, $interviewer->toArray());

            return response()->json([
                'message' => "Interviewer '{$name}' has historical interview records and has been deactivated.",
                'interviewer' => $interviewer,
            ]);
        }

        $interviewer->delete();
        AuditLog::log('deleted_mock_interviewer', null, $old, null);

        return response()->json([
            'message' => "Interviewer '{$name}' deleted successfully.",
        ]);
    }

    /**
     * List Mock Interview Slots.
     */
    public function slots(Request $request)
    {
        $query = MockInterviewSlot::with(['interviewer:id,name,email,company,designation,is_active', 'interview.student:id,name,email']);

        if ($request->filled('interviewer_id') && $request->interviewer_id !== 'all') {
            $query->where('interviewer_id', (int) $request->interviewer_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('slot_date')) {
            $query->where('slot_date', $request->slot_date);
        }

        $slots = $query->orderBy('slot_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->paginate($request->input('per_page', 50));

        return response()->json($slots);
    }

    /**
     * Create an interview slot.
     */
    public function storeSlot(Request $request)
    {
        $validated = $request->validate([
            'interviewer_id' => 'required|exists:mock_interviewers,id',
            'slot_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'duration_minutes' => 'nullable|integer|min:15|max:180',
            'meeting_link' => 'nullable|string|max:500',
            'platform' => 'nullable|string|max:100',
            'instructions' => 'nullable|string|max:2000',
        ]);

        // Duplicate slot check
        $exists = MockInterviewSlot::where('interviewer_id', $validated['interviewer_id'])
            ->where('slot_date', $validated['slot_date'])
            ->where('start_time', $validated['start_time'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A slot for this interviewer on the selected date and start time already exists.',
            ], 422);
        }

        $validated['created_by'] = $request->user()->id;
        $validated['status'] = MockInterviewSlot::STATUS_AVAILABLE;

        $slot = MockInterviewSlot::create($validated);

        AuditLog::log('created_mock_interview_slot', $slot, null, $slot->toArray());

        return response()->json([
            'message' => 'Interview slot created successfully.',
            'slot' => $slot->load('interviewer'),
        ], 201);
    }

    /**
     * Update an interview slot.
     */
    public function updateSlot(Request $request, MockInterviewSlot $slot)
    {
        $old = $slot->toArray();

        $validated = $request->validate([
            'interviewer_id' => 'sometimes|required|exists:mock_interviewers,id',
            'slot_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i|after:start_time',
            'duration_minutes' => 'nullable|integer|min:15|max:180',
            'meeting_link' => 'nullable|string|max:500',
            'platform' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:available,booked,completed,cancelled',
            'instructions' => 'nullable|string|max:2000',
        ]);

        $slot->update($validated);

        AuditLog::log('updated_mock_interview_slot', $slot, $old, $slot->toArray());

        return response()->json([
            'message' => 'Interview slot updated successfully.',
            'slot' => $slot->load('interviewer'),
        ]);
    }

    /**
     * Delete an interview slot.
     */
    public function destroySlot(MockInterviewSlot $slot)
    {
        if ($slot->status === MockInterviewSlot::STATUS_BOOKED) {
            return response()->json([
                'message' => 'Cannot delete a slot that is currently booked by a student. Please cancel the booking first.',
            ], 422);
        }

        $old = $slot->toArray();
        $slot->delete();

        AuditLog::log('deleted_mock_interview_slot', null, $old, null);

        return response()->json([
            'message' => 'Interview slot deleted successfully.',
        ]);
    }

    /**
     * List Mock Interview Bookings.
     */
    public function bookings(Request $request)
    {
        $query = MockInterview::with([
            'student:id,name,email,phone,student_id,avatar',
            'slot',
            'interviewer',
            'course:id,title,code',
            'batch:id,name,code',
            'evaluation',
            'canceller:id,name',
        ]);

        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('booking_code', 'like', $term)
                    ->orWhereHas('student', function ($sq) use ($term) {
                        $sq->where('name', 'like', $term)->orWhere('email', 'like', $term);
                    })
                    ->orWhereHas('interviewer', function ($iq) use ($term) {
                        $iq->where('name', 'like', $term)->orWhere('company', 'like', $term);
                    });
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('interviewer_id') && $request->interviewer_id !== 'all') {
            $query->where('interviewer_id', (int) $request->interviewer_id);
        }

        if ($request->filled('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }

        $bookings = $query->orderBy('scheduled_at', 'desc')->paginate($request->input('per_page', 50));

        return response()->json($bookings);
    }

    /**
     * Show single booking details.
     */
    public function showBooking(MockInterview $interview)
    {
        $interview->load([
            'student:id,name,email,phone,student_id,avatar',
            'slot',
            'interviewer',
            'course:id,title,code',
            'batch:id,name,code',
            'evaluation.evaluator:id,name,email',
            'canceller:id,name',
        ]);

        return response()->json($interview);
    }

    /**
     * Update booking status (confirm, completed, no-show, etc.).
     */
    public function updateBookingStatus(Request $request, MockInterview $interview)
    {
        $old = $interview->toArray();

        $validated = $request->validate([
            'status' => 'required|string|in:booked,confirmed,completed,cancelled,rescheduled,no_show',
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $interview->update($validated);

        if ($validated['status'] === MockInterview::STATUS_COMPLETED && $interview->slot) {
            $interview->slot->update(['status' => MockInterviewSlot::STATUS_COMPLETED]);
        }

        AuditLog::log('updated_mock_interview_status', $interview, $old, $interview->toArray());

        return response()->json([
            'message' => 'Mock interview status updated successfully.',
            'interview' => $interview->load(['student', 'slot', 'interviewer', 'evaluation']),
        ]);
    }

    /**
     * Reassign interviewer to an existing booking.
     */
    public function reassignInterviewer(Request $request, MockInterview $interview)
    {
        $validated = $request->validate([
            'interviewer_id' => 'required|exists:mock_interviewers,id',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $oldInterviewerId = $interview->interviewer_id;
        $newInterviewer = MockInterviewer::findOrFail($validated['interviewer_id']);

        $interview->update([
            'interviewer_id' => $newInterviewer->id,
            'admin_notes' => $validated['admin_notes'] ?? $interview->admin_notes,
        ]);

        if ($interview->slot) {
            $interview->slot->update(['interviewer_id' => $newInterviewer->id]);
        }

        AuditLog::log('reassigned_mock_interview_interviewer', $interview, ['old_interviewer_id' => $oldInterviewerId], ['new_interviewer_id' => $newInterviewer->id]);

        return response()->json([
            'message' => "Interview reassigned to {$newInterviewer->name} successfully.",
            'interview' => $interview->load(['student', 'slot', 'interviewer']),
        ]);
    }

    /**
     * Reschedule booking to another slot (Admin action).
     */
    public function rescheduleBooking(Request $request, MockInterview $interview)
    {
        $validated = $request->validate([
            'slot_id' => 'required|exists:mock_interview_slots,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $rescheduled = MockInterviewService::rescheduleBooking(
            $interview,
            (int) $validated['slot_id'],
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Mock interview rescheduled successfully.',
            'interview' => $rescheduled,
        ]);
    }

    /**
     * Cancel booking with reason (Admin action).
     */
    public function cancelBooking(Request $request, MockInterview $interview)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $cancelled = MockInterviewService::cancelBooking(
            $interview,
            $request->user(),
            trim($validated['reason'])
        );

        return response()->json([
            'message' => 'Mock interview booking cancelled successfully.',
            'interview' => $cancelled,
        ]);
    }

    /**
     * Evaluate mock interview (Admin or Interviewer action).
     */
    public function evaluateBooking(Request $request, MockInterview $interview)
    {
        $validated = $request->validate([
            'technical_knowledge' => 'required|integer|min:1|max:10',
            'programming_problem_solving' => 'required|integer|min:1|max:10',
            'communication' => 'required|integer|min:1|max:10',
            'confidence' => 'required|integer|min:1|max:10',
            'project_knowledge' => 'required|integer|min:1|max:10',
            'interview_readiness' => 'required|integer|min:1|max:10',
            'overall_rating' => 'nullable|numeric|min:1|max:10',
            'strengths' => 'required|string|max:4000',
            'areas_for_improvement' => 'required|string|max:4000',
            'interviewer_remarks' => 'nullable|string|max:4000',
            'recommendation' => 'required|string|in:Ready for Placement,Needs Improvement,Re-interview Required',
            'is_published_to_student' => 'nullable|boolean',
        ]);

        $evaluation = MockInterviewService::submitEvaluation(
            $interview,
            $validated,
            $request->user()
        );

        return response()->json([
            'message' => 'Mock interview evaluation submitted successfully! Placement eligibility updated.',
            'evaluation' => $evaluation,
            'interview' => $interview->fresh(['slot', 'interviewer', 'student', 'evaluation']),
        ], 201);
    }

    /**
     * List all evaluations with filters.
     */
    public function evaluations(Request $request)
    {
        $query = MockInterviewEvaluation::with([
            'student:id,name,email,phone,student_id,avatar',
            'interviewer:id,name,company,designation',
            'evaluator:id,name,email',
            'interview.course:id,title,code',
        ]);

        if ($request->filled('recommendation') && $request->recommendation !== 'all') {
            $query->where('recommendation', $request->recommendation);
        }

        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('student', fn ($sq) => $sq->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhereHas('interviewer', fn ($iq) => $iq->where('name', 'like', $term)->orWhere('company', 'like', $term));
            });
        }

        $evaluations = $query->orderBy('evaluated_at', 'desc')->paginate($request->input('per_page', 50));

        return response()->json($evaluations);
    }
}
