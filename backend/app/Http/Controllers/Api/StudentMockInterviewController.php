<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MockInterview;
use App\Models\MockInterviewer;
use App\Models\MockInterviewSlot;
use App\Services\MockInterviewService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StudentMockInterviewController extends Controller
{
    /**
     * Check authenticated student's mock interview eligibility and current booking state.
     */
    public function eligibility(Request $request)
    {
        $user = $request->user();

        if ($user->isCompany() || $user->role === 'tutor') {
            return response()->json([
                'message' => 'Only enrolled students can participate in mandatory mock interviews.',
            ], 403);
        }

        $result = MockInterviewService::checkStudentEligibility($user);

        return response()->json($result);
    }

    /**
     * List upcoming available slots with safe interviewer projections.
     */
    public function slots(Request $request)
    {
        $user = $request->user();

        if ($user && ($user->isCompany() || $user->role === 'tutor')) {
            return response()->json([
                'message' => 'Only enrolled students can view mock interview slots.',
            ], 403);
        }

        $query = MockInterviewSlot::available()
            ->upcoming()
            ->with(['interviewer' => function ($q) {
                $q->active()->select(['id', 'name', 'designation', 'company', 'years_of_experience', 'skills', 'bio', 'is_active']);
            }])
            ->whereHas('interviewer', function ($q) {
                $q->active();
            });

        if ($request->filled('date')) {
            $query->where('slot_date', $request->date);
        }

        if ($request->filled('interviewer_id') && $request->interviewer_id !== 'all') {
            $query->where('interviewer_id', (int) $request->interviewer_id);
        }

        $slots = $query->orderBy('slot_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json($slots);
    }

    /**
     * Book an available mock interview slot for the authenticated student.
     */
    public function book(Request $request)
    {
        $user = $request->user();

        if ($user->isCompany() || $user->role === 'tutor') {
            return response()->json([
                'message' => 'Only enrolled students can book mock interviews.',
            ], 403);
        }

        $validated = $request->validate([
            'slot_id' => 'required|exists:mock_interview_slots,id',
            'student_notes' => 'nullable|string|max:1000',
            'course_id' => 'nullable|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
        ]);

        $booking = MockInterviewService::bookSlot(
            $user,
            (int) $validated['slot_id'],
            $validated['student_notes'] ?? null,
            isset($validated['course_id']) ? (int) $validated['course_id'] : null,
            isset($validated['batch_id']) ? (int) $validated['batch_id'] : null
        );

        return response()->json([
            'message' => "Your mock interview has been booked successfully! Booking Code: {$booking->booking_code}",
            'interview' => $booking,
        ], 201);
    }

    /**
     * Get authenticated student's mock interview history and evaluations.
     */
    public function myInterviews(Request $request)
    {
        $user = $request->user();

        if ($user->isCompany() || $user->role === 'tutor') {
            return response()->json([
                'message' => 'Only enrolled students can view their mock interviews.',
            ], 403);
        }

        $interviews = MockInterview::forStudent($user->id)
            ->with([
                'slot',
                'interviewer:id,name,designation,company,years_of_experience,skills,bio',
                'course:id,title,code',
                'batch:id,name,code',
                'evaluation' => function ($q) {
                    $q->where('is_published_to_student', true);
                },
            ])
            ->orderBy('scheduled_at', 'desc')
            ->get();

        return response()->json($interviews);
    }

    /**
     * Show details of a single mock interview.
     */
    public function show(Request $request, MockInterview $interview)
    {
        $user = $request->user();

        if ($interview->student_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized access to this interview.'], 403);
        }

        $interview->load([
            'slot',
            'interviewer:id,name,designation,company,years_of_experience,skills,bio',
            'course:id,title,code',
            'batch:id,name,code',
            'evaluation' => function ($q) {
                $q->where('is_published_to_student', true);
            },
        ]);

        return response()->json($interview);
    }

    /**
     * Cancel an active mock interview booking.
     */
    public function cancel(Request $request, MockInterview $interview)
    {
        $user = $request->user();

        if ($interview->student_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized access to this interview.'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $cancelled = MockInterviewService::cancelBooking(
            $interview,
            $user,
            trim($validated['reason'])
        );

        return response()->json([
            'message' => 'Your mock interview booking has been cancelled.',
            'interview' => $cancelled,
        ]);
    }

    /**
     * Reschedule an active mock interview booking to a new slot.
     */
    public function reschedule(Request $request, MockInterview $interview)
    {
        $user = $request->user();

        if ($interview->student_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized access to this interview.'], 403);
        }

        $validated = $request->validate([
            'slot_id' => 'required|exists:mock_interview_slots,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $rescheduled = MockInterviewService::rescheduleBooking(
            $interview,
            (int) $validated['slot_id'],
            $user,
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Your mock interview has been rescheduled successfully.',
            'interview' => $rescheduled,
        ]);
    }
}
