<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\MockInterview;
use App\Models\MockInterviewEvaluation;
use App\Models\MockInterviewer;
use App\Models\MockInterviewSlot;
use App\Models\StudentPlacementEligibility;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MockInterviewService
{
    /**
     * Check student's course completion and mock interview eligibility with comprehensive breakdown.
     */
    public static function checkStudentEligibility(User $student): array
    {
        // 1. Fetch active/completed course enrollments
        $enrollments = CourseEnrollment::where('user_id', $student->id)
            ->with(['course:id,title,code,slug'])
            ->get();

        $courseSummaries = [];
        $hasCompletedAnyCourse = false;
        $reasons = [];

        foreach ($enrollments as $enrollment) {
            $courseId = $enrollment->course_id;
            $totalPublishedLessons = Lesson::where('course_id', $courseId)->where('is_published', true)->count();
            $completedLessons = LessonProgress::where('user_id', $student->id)
                ->where('course_id', $courseId)
                ->where('completed', true)
                ->count();

            $percentage = $totalPublishedLessons > 0
                ? round(($completedLessons / $totalPublishedLessons) * 100, 1)
                : (float) $enrollment->progress_percentage;

            // Strongest course completion rule: If published lessons exist, student must complete them all or reach 100% progress
            if ($totalPublishedLessons > 0) {
                $isCompleted = ($completedLessons >= $totalPublishedLessons) || ($percentage >= 100.0);
            } else {
                $isCompleted = ($percentage >= 100.0) || ($enrollment->status === 'completed');
            }

            if ($isCompleted) {
                $hasCompletedAnyCourse = true;
            }

            $courseSummaries[] = [
                'course_id' => $courseId,
                'course_title' => $enrollment->course?->title ?? 'Course #' . $courseId,
                'course_code' => $enrollment->course?->code,
                'status' => $enrollment->status,
                'total_lessons' => $totalPublishedLessons,
                'completed_lessons' => $completedLessons,
                'progress_percentage' => $percentage,
                'is_completed' => $isCompleted,
            ];
        }

        // 2. Check for earned certificates
        $certificatesCount = Certificate::where('user_id', $student->id)->count();
        if ($certificatesCount > 0) {
            $hasCompletedAnyCourse = true;
        }

        // 3. Check tracked / overridden eligibility record
        $eligibilityRecord = StudentPlacementEligibility::where('user_id', $student->id)
            ->with(['dashboardStatusUpdatedBy:id,name,email', 'dashboardEnabledBy:id,name,email', 'overrideAdmin:id,name,email'])
            ->first();
        $isAdminOverride = (bool) ($eligibilityRecord?->is_admin_override ?? false);

        // Course eligibility determination
        $isCourseEligible = $hasCompletedAnyCourse || $isAdminOverride;

        if ($hasCompletedAnyCourse) {
            $reasons[] = 'Student has successfully completed course curriculum requirements.';
        }
        if ($certificatesCount > 0) {
            $reasons[] = "Student has {$certificatesCount} verified course completion certificate(s).";
        }
        if ($isAdminOverride) {
            $reasons[] = "Administrator override granted: {$eligibilityRecord->override_reason}";
        }
        if (! $isCourseEligible) {
            $reasons[] = 'Student must complete 100% of lessons in at least one enrolled course to qualify.';
        }

        // 4. Check mock interviews
        $completedMock = MockInterview::where('student_id', $student->id)
            ->where('status', MockInterview::STATUS_COMPLETED)
            ->with(['slot', 'interviewer', 'evaluation'])
            ->latest('scheduled_at')
            ->first();

        $activeBooking = MockInterview::where('student_id', $student->id)
            ->whereIn('status', [MockInterview::STATUS_BOOKED, MockInterview::STATUS_CONFIRMED, MockInterview::STATUS_RESCHEDULED])
            ->with(['slot', 'interviewer', 'course', 'batch'])
            ->latest()
            ->first();

        $latestEvaluation = MockInterviewEvaluation::where('student_id', $student->id)
            ->with(['interviewer', 'interview'])
            ->latest('evaluated_at')
            ->first();

        // 5. Batch information
        $primaryBatch = $student->activeBatches()->with('course:id,title,code')->first();
        $batchSummary = $primaryBatch ? [
            'id' => $primaryBatch->id,
            'name' => $primaryBatch->name,
            'code' => $primaryBatch->code,
            'course_title' => $primaryBatch->course?->title,
        ] : null;

        // 6. Placement Activation 3-Point Eligibility Criteria
        // Requirement 1: Course completion satisfied
        // Requirement 2: Mandatory mock interview completed
        // Requirement 3: Interview evaluation/result exists
        $isMockCompleted = (bool) $completedMock || (bool) $latestEvaluation;
        $hasEvaluation = (bool) $latestEvaluation;
        $isEligibleForActivation = ($isCourseEligible && $isMockCompleted && $hasEvaluation) || $isAdminOverride;

        // 7. Placement Dashboard Status (DISABLED, ELIGIBLE, ENABLED, SUSPENDED)
        $persistedStatus = $eligibilityRecord?->dashboard_status;
        if ($persistedStatus === StudentPlacementEligibility::STATUS_ENABLED) {
            $dashboardStatus = StudentPlacementEligibility::STATUS_ENABLED;
        } elseif ($persistedStatus === StudentPlacementEligibility::STATUS_SUSPENDED) {
            $dashboardStatus = StudentPlacementEligibility::STATUS_SUSPENDED;
        } elseif ($persistedStatus === StudentPlacementEligibility::STATUS_DISABLED && ! $isEligibleForActivation) {
            $dashboardStatus = StudentPlacementEligibility::STATUS_DISABLED;
        } elseif ($isEligibleForActivation) {
            $dashboardStatus = ($persistedStatus === StudentPlacementEligibility::STATUS_DISABLED && $eligibilityRecord?->dashboard_status_updated_by)
                ? StudentPlacementEligibility::STATUS_DISABLED
                : StudentPlacementEligibility::STATUS_ELIGIBLE;
        } else {
            $dashboardStatus = StudentPlacementEligibility::STATUS_DISABLED;
        }

        $placementDashboardEnabled = ($dashboardStatus === StudentPlacementEligibility::STATUS_ENABLED);

        // Mock interview lifecycle state
        $mockInterviewState = 'not_scheduled';
        if ($activeBooking) {
            $mockInterviewState = $activeBooking->status;
        } elseif ($completedMock) {
            $mockInterviewState = 'completed';
        } elseif ($latestEvaluation) {
            $mockInterviewState = $latestEvaluation->isReadyForPlacement() ? 'passed' : 'reinterview_required';
        }

        $mockInterviewSummary = null;
        if ($completedMock || $activeBooking || $latestEvaluation) {
            $interviewSource = $completedMock ?: $activeBooking;
            $mockInterviewSummary = [
                'status' => $mockInterviewState,
                'interview_date' => $interviewSource?->scheduled_at?->toDateTimeString(),
                'interviewer_name' => $interviewSource?->interviewer?->name ?? $latestEvaluation?->interviewer?->name,
                'interviewer_company' => $interviewSource?->interviewer?->company ?? $latestEvaluation?->interviewer?->company,
                'score' => $latestEvaluation?->overall_rating,
                'recommendation' => $latestEvaluation?->recommendation,
                'booking_code' => $interviewSource?->booking_code,
            ];
        }

        return [
            'student_id' => $student->id,
            'student_name' => $student->name,
            'student_email' => $student->email,
            'phone' => $student->phone,
            'student_code' => $student->student_id,
            'is_eligible' => $isCourseEligible,
            'course_completed' => $hasCompletedAnyCourse,
            'certificates_count' => $certificatesCount,
            'is_admin_override' => $isAdminOverride,
            'override_reason' => $eligibilityRecord?->override_reason,
            'reasons' => $reasons,
            'courses' => $courseSummaries,
            'batch' => $batchSummary,
            'mock_interview_state' => $mockInterviewState,
            'mock_interview' => $mockInterviewSummary,
            'has_active_booking' => (bool) $activeBooking,
            'active_booking' => $activeBooking,
            'completed_mock' => $completedMock,
            'latest_evaluation' => $latestEvaluation,
            'is_eligible_for_activation' => $isEligibleForActivation,
            'placement_dashboard_status' => $dashboardStatus,
            'placement_dashboard_enabled' => $placementDashboardEnabled,
            'dashboard_status_reason' => $eligibilityRecord?->dashboard_status_reason,
            'dashboard_status_updated_at' => $eligibilityRecord?->dashboard_status_updated_at?->toIso8601String(),
            'dashboard_status_updated_by' => $eligibilityRecord?->dashboardStatusUpdatedBy,
            'dashboard_enabled_at' => $eligibilityRecord?->dashboard_enabled_at?->toIso8601String(),
            'dashboard_enabled_by' => $eligibilityRecord?->dashboardEnabledBy,
            'placement_eligible' => $placementDashboardEnabled || ($isAdminOverride && (bool) $eligibilityRecord?->placement_eligible),
        ];
    }

    /**
     * Book an available mock interview slot atomically with row locking.
     */
    public static function bookSlot(User $student, int $slotId, ?string $studentNotes = null, ?int $courseId = null, ?int $batchId = null): MockInterview
    {
        // 1. Eligibility Check
        $eligibility = self::checkStudentEligibility($student);
        if (! $eligibility['is_eligible']) {
            abort(422, 'You are not yet eligible for mock interviews. Complete 100% of your course lessons first.');
        }

        // 2. Prevent Multiple Active Concurrent Bookings
        $existingActive = MockInterview::where('student_id', $student->id)
            ->whereIn('status', [MockInterview::STATUS_BOOKED, MockInterview::STATUS_CONFIRMED, MockInterview::STATUS_RESCHEDULED])
            ->exists();

        if ($existingActive) {
            abort(422, 'You already have an active mock interview booking. You must complete, cancel, or reschedule your existing session first.');
        }

        // 3. Atomic Slot Reservation
        return DB::transaction(function () use ($student, $slotId, $studentNotes, $courseId, $batchId) {
            /** @var MockInterviewSlot|null $slot */
            $slot = MockInterviewSlot::where('id', $slotId)
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                abort(404, 'The requested interview slot does not exist.');
            }

            if ($slot->status !== MockInterviewSlot::STATUS_AVAILABLE) {
                abort(422, 'This interview slot is no longer available. Please select another slot.');
            }

            if ($slot->isPast()) {
                abort(422, 'Cannot book an interview slot that is in the past.');
            }

            // Derive course / batch if not provided
            if (! $courseId) {
                $enrollment = CourseEnrollment::where('user_id', $student->id)->latest()->first();
                $courseId = $enrollment?->course_id;
            }

            if (! $batchId) {
                $batchStudent = $student->activeBatches()->first();
                $batchId = $batchStudent?->id;
            }

            // Mark slot as booked
            $slot->update(['status' => MockInterviewSlot::STATUS_BOOKED]);

            $scheduledAt = Carbon::parse($slot->slot_date->format('Y-m-d') . ' ' . $slot->start_time);
            $bookingCode = 'MIT-MOCK-' . strtoupper(Str::random(8));

            $interview = MockInterview::create([
                'booking_code' => $bookingCode,
                'student_id' => $student->id,
                'slot_id' => $slot->id,
                'interviewer_id' => $slot->interviewer_id,
                'course_id' => $courseId,
                'batch_id' => $batchId,
                'scheduled_at' => $scheduledAt,
                'status' => MockInterview::STATUS_BOOKED,
                'student_notes' => $studentNotes,
            ]);

            // Update or create StudentPlacementEligibility
            StudentPlacementEligibility::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'course_completed' => true,
                    'mock_interview_status' => 'booked',
                    'last_evaluated_at' => now(),
                ]
            );

            AuditLog::log('booked_mock_interview', $interview, null, [
                'student_id' => $student->id,
                'slot_id' => $slot->id,
                'interviewer_id' => $slot->interviewer_id,
                'booking_code' => $bookingCode,
                'scheduled_at' => $scheduledAt->toDateTimeString(),
            ]);

            return $interview->load(['slot', 'interviewer', 'course', 'batch']);
        });
    }

    /**
     * Cancel an existing mock interview booking.
     */
    public static function cancelBooking(MockInterview $interview, User $actor, string $reason): MockInterview
    {
        if (! in_array($interview->status, [MockInterview::STATUS_BOOKED, MockInterview::STATUS_CONFIRMED, MockInterview::STATUS_RESCHEDULED], true)) {
            abort(422, "Cannot cancel an interview with status '{$interview->status}'.");
        }

        return DB::transaction(function () use ($interview, $actor, $reason) {
            $old = $interview->toArray();

            $interview->update([
                'status' => MockInterview::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            // Re-open the slot if slot date is in the future
            if ($interview->slot && ! $interview->slot->isPast()) {
                $interview->slot->update(['status' => MockInterviewSlot::STATUS_AVAILABLE]);
            }

            StudentPlacementEligibility::where('user_id', $interview->student_id)
                ->update(['mock_interview_status' => 'cancelled']);

            AuditLog::log('cancelled_mock_interview', $interview, $old, [
                'cancelled_by' => $actor->id,
                'reason' => $reason,
            ]);

            return $interview->fresh(['slot', 'interviewer', 'canceller']);
        });
    }

    /**
     * Reschedule an existing mock interview booking to a new slot.
     */
    public static function rescheduleBooking(MockInterview $interview, int $newSlotId, User $actor, ?string $reason = null): MockInterview
    {
        if (! in_array($interview->status, [MockInterview::STATUS_BOOKED, MockInterview::STATUS_CONFIRMED, MockInterview::STATUS_RESCHEDULED], true)) {
            abort(422, "Cannot reschedule an interview with status '{$interview->status}'.");
        }

        return DB::transaction(function () use ($interview, $newSlotId, $actor, $reason) {
            $oldSlot = $interview->slot;

            /** @var MockInterviewSlot|null $newSlot */
            $newSlot = MockInterviewSlot::where('id', $newSlotId)
                ->lockForUpdate()
                ->first();

            if (! $newSlot) {
                abort(404, 'The new interview slot does not exist.');
            }

            if ($newSlot->status !== MockInterviewSlot::STATUS_AVAILABLE) {
                abort(422, 'The new interview slot is no longer available.');
            }

            if ($newSlot->isPast()) {
                abort(422, 'Cannot reschedule to a slot that is in the past.');
            }

            // Release old slot
            if ($oldSlot && ! $oldSlot->isPast()) {
                $oldSlot->update(['status' => MockInterviewSlot::STATUS_AVAILABLE]);
            }

            // Reserve new slot
            $newSlot->update(['status' => MockInterviewSlot::STATUS_BOOKED]);

            $newScheduledAt = Carbon::parse($newSlot->slot_date->format('Y-m-d') . ' ' . $newSlot->start_time);

            $oldData = $interview->toArray();

            $interview->update([
                'slot_id' => $newSlot->id,
                'interviewer_id' => $newSlot->interviewer_id,
                'scheduled_at' => $newScheduledAt,
                'status' => MockInterview::STATUS_RESCHEDULED,
                'admin_notes' => $reason ? ($interview->admin_notes ? $interview->admin_notes . "\nReschedule Note: " . $reason : "Reschedule Note: " . $reason) : $interview->admin_notes,
            ]);

            StudentPlacementEligibility::where('user_id', $interview->student_id)
                ->update(['mock_interview_status' => 'rescheduled']);

            AuditLog::log('rescheduled_mock_interview', $interview, $oldData, [
                'rescheduled_by' => $actor->id,
                'old_slot_id' => $oldSlot?->id,
                'new_slot_id' => $newSlot->id,
                'new_scheduled_at' => $newScheduledAt->toDateTimeString(),
            ]);

            return $interview->fresh(['slot', 'interviewer', 'course', 'batch']);
        });
    }

    /**
     * Submit structured 6-factor evaluation scorecard and compute placement eligibility.
     */
    public static function submitEvaluation(MockInterview $interview, array $data, User $evaluator): MockInterviewEvaluation
    {
        return DB::transaction(function () use ($interview, $data, $evaluator) {
            // Compute overall rating if not explicitly provided (average of the 6 factors).
            // Every factor MUST be explicitly supplied — no fabricated placeholder scores.
            $factorKeys = [
                'technical_knowledge',
                'programming_problem_solving',
                'communication',
                'confidence',
                'project_knowledge',
                'interview_readiness',
            ];
            $missingFactors = array_values(array_diff($factorKeys, array_keys($data)));
            if ($missingFactors !== []) {
                throw new \InvalidArgumentException(
                    'All evaluation factors must be provided. Missing: ' . implode(', ', $missingFactors) . '.'
                );
            }
            $factors = array_map(fn (string $key) => (float) $data[$key], $factorKeys);
            $computedRating = round(array_sum($factors) / count($factors), 1);
            $overallRating = isset($data['overall_rating']) ? (float) $data['overall_rating'] : $computedRating;

            $recommendation = $data['recommendation']; // 'Ready for Placement', 'Needs Improvement', 'Re-interview Required'

            $evaluation = MockInterviewEvaluation::updateOrCreate(
                ['mock_interview_id' => $interview->id],
                [
                    'interviewer_id' => $data['interviewer_id'] ?? $interview->interviewer_id,
                    'evaluated_by' => $evaluator->id,
                    'student_id' => $interview->student_id,
                    'technical_knowledge' => (int) $data['technical_knowledge'],
                    'programming_problem_solving' => (int) $data['programming_problem_solving'],
                    'communication' => (int) $data['communication'],
                    'confidence' => (int) $data['confidence'],
                    'project_knowledge' => (int) $data['project_knowledge'],
                    'interview_readiness' => (int) $data['interview_readiness'],
                    'overall_rating' => $overallRating,
                    'strengths' => trim($data['strengths']),
                    'areas_for_improvement' => trim($data['areas_for_improvement']),
                    'interviewer_remarks' => isset($data['interviewer_remarks']) ? trim($data['interviewer_remarks']) : null,
                    'recommendation' => $recommendation,
                    'is_published_to_student' => $data['is_published_to_student'] ?? true,
                    'evaluated_at' => now(),
                ]
            );

            // Mark interview and slot as completed
            $interview->update(['status' => MockInterview::STATUS_COMPLETED]);
            if ($interview->slot) {
                $interview->slot->update(['status' => MockInterviewSlot::STATUS_COMPLETED]);
            }

            // Update Placement Eligibility
            $isReady = ($recommendation === MockInterviewEvaluation::REC_READY_FOR_PLACEMENT);
            StudentPlacementEligibility::updateOrCreate(
                ['user_id' => $interview->student_id],
                [
                    'course_completed' => true,
                    'mock_interview_status' => $isReady ? 'passed' : 'needs_improvement',
                    'placement_eligible' => $isReady,
                    'last_evaluated_at' => now(),
                ]
            );

            AuditLog::log('submitted_mock_interview_evaluation', $evaluation, null, [
                'interview_id' => $interview->id,
                'student_id' => $interview->student_id,
                'recommendation' => $recommendation,
                'overall_rating' => $overallRating,
                'placement_eligible' => $isReady,
                'evaluated_by' => $evaluator->id,
            ]);

            return $evaluation->load(['interview', 'interviewer', 'evaluator', 'student']);
        });
    }

    /**
     * Admin override for student placement eligibility.
     */
    public static function overridePlacementEligibility(User $student, bool $eligible, User $admin, string $reason, ?string $notes = null): StudentPlacementEligibility
    {
        $old = StudentPlacementEligibility::where('user_id', $student->id)->first();
        $oldData = $old ? $old->toArray() : null;

        $record = StudentPlacementEligibility::updateOrCreate(
            ['user_id' => $student->id],
            [
                'placement_eligible' => $eligible,
                'is_admin_override' => true,
                'override_reason' => $reason,
                'override_by' => $admin->id,
                'notes' => $notes,
                'last_evaluated_at' => now(),
            ]
        );

        AuditLog::log('overrode_student_placement_eligibility', $record, $oldData, [
            'student_id' => $student->id,
            'placement_eligible' => $eligible,
            'override_reason' => $reason,
            'admin_id' => $admin->id,
        ]);

        return $record->load(['user', 'overrideAdmin']);
    }

    /**
     * Admin action to enable, disable, suspend, or re-enable student's placement dashboard.
     */
    public static function updatePlacementDashboardStatus(User $student, string $action, User $admin, ?string $reason = null): StudentPlacementEligibility
    {
        $old = StudentPlacementEligibility::where('user_id', $student->id)->first();
        $oldData = $old ? $old->toArray() : null;
        $prevStatus = $old?->dashboard_status ?? StudentPlacementEligibility::STATUS_DISABLED;

        $action = strtolower(trim($action));

        if (in_array($action, ['enable', 'reenable'], true)) {
            $el = self::checkStudentEligibility($student);
            if (! $el['is_eligible_for_activation']) {
                abort(422, 'Student is not yet eligible for placement dashboard activation. Course completion, mock interview completion, and evaluation scorecard are required.');
            }

            $newStatus = StudentPlacementEligibility::STATUS_ENABLED;

            $record = StudentPlacementEligibility::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'course_completed' => true,
                    'placement_eligible' => true,
                    'dashboard_status' => $newStatus,
                    'dashboard_status_reason' => $reason,
                    'dashboard_status_updated_by' => $admin->id,
                    'dashboard_status_updated_at' => now(),
                    'dashboard_enabled_at' => $old?->dashboard_enabled_at ?? now(),
                    'dashboard_enabled_by' => $old?->dashboard_enabled_by ?? $admin->id,
                    'last_evaluated_at' => now(),
                ]
            );

            $auditEvent = ($action === 'reenable' || $prevStatus === StudentPlacementEligibility::STATUS_SUSPENDED || ($prevStatus === StudentPlacementEligibility::STATUS_DISABLED && $old?->dashboard_enabled_at))
                ? 'placement_dashboard_reenabled'
                : 'placement_dashboard_enabled';

            AuditLog::log($auditEvent, $record, $oldData, [
                'administrator' => $admin->id,
                'student_id' => $student->id,
                'previous_status' => $prevStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'timestamp' => now()->toIso8601String(),
            ]);

            return $record->load(['user', 'overrideAdmin', 'dashboardStatusUpdatedBy', 'dashboardEnabledBy']);
        }

        if ($action === 'disable') {
            $newStatus = StudentPlacementEligibility::STATUS_DISABLED;

            $record = StudentPlacementEligibility::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'placement_eligible' => false,
                    'dashboard_status' => $newStatus,
                    'dashboard_status_reason' => $reason,
                    'dashboard_status_updated_by' => $admin->id,
                    'dashboard_status_updated_at' => now(),
                ]
            );

            AuditLog::log('placement_dashboard_disabled', $record, $oldData, [
                'administrator' => $admin->id,
                'student_id' => $student->id,
                'previous_status' => $prevStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'timestamp' => now()->toIso8601String(),
            ]);

            return $record->load(['user', 'overrideAdmin', 'dashboardStatusUpdatedBy', 'dashboardEnabledBy']);
        }

        if ($action === 'suspend') {
            $newStatus = StudentPlacementEligibility::STATUS_SUSPENDED;

            $record = StudentPlacementEligibility::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'placement_eligible' => false,
                    'dashboard_status' => $newStatus,
                    'dashboard_status_reason' => $reason,
                    'dashboard_status_updated_by' => $admin->id,
                    'dashboard_status_updated_at' => now(),
                ]
            );

            AuditLog::log('placement_dashboard_suspended', $record, $oldData, [
                'administrator' => $admin->id,
                'student_id' => $student->id,
                'previous_status' => $prevStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'timestamp' => now()->toIso8601String(),
            ]);

            return $record->load(['user', 'overrideAdmin', 'dashboardStatusUpdatedBy', 'dashboardEnabledBy']);
        }

        abort(422, "Invalid placement dashboard action '{$action}'. Supported actions are: enable, disable, suspend, reenable.");
    }

    /**
     * Check if a student is placement eligible (gateway for job applications).
     */
    public static function isStudentPlacementEligible(User $student): bool
    {
        $eligibility = StudentPlacementEligibility::where('user_id', $student->id)->first();
        if ($eligibility && $eligibility->is_admin_override && $eligibility->placement_eligible) {
            return true;
        }

        if ($eligibility && $eligibility->dashboard_status === StudentPlacementEligibility::STATUS_SUSPENDED) {
            return false;
        }

        if ($eligibility && $eligibility->dashboard_status === StudentPlacementEligibility::STATUS_DISABLED && $eligibility->dashboard_status_updated_by) {
            return false;
        }

        if ($eligibility && $eligibility->dashboard_status === StudentPlacementEligibility::STATUS_ENABLED) {
            return true;
        }

        if ($eligibility && $eligibility->placement_eligible) {
            return true;
        }

        // Direct evaluation check
        $passedEvaluation = MockInterviewEvaluation::where('student_id', $student->id)
            ->where('recommendation', MockInterviewEvaluation::REC_READY_FOR_PLACEMENT)
            ->exists();

        return $passedEvaluation;
    }

    /**
     * Get Aggregated Mock Interview Statistics for Admin C-Panel.
     */
    public static function getAdminStats(): array
    {
        $totalInterviewers = MockInterviewer::count();
        $activeInterviewers = MockInterviewer::active()->count();

        $totalSlots = MockInterviewSlot::count();
        $availableSlots = MockInterviewSlot::available()->upcoming()->count();

        $totalBookings = MockInterview::count();
        $scheduledBookings = MockInterview::whereIn('status', [MockInterview::STATUS_BOOKED, MockInterview::STATUS_CONFIRMED])->count();
        $completedBookings = MockInterview::where('status', MockInterview::STATUS_COMPLETED)->count();
        $cancelledBookings = MockInterview::where('status', MockInterview::STATUS_CANCELLED)->count();
        $noShowBookings = MockInterview::where('status', MockInterview::STATUS_NO_SHOW)->count();

        $totalEvaluations = MockInterviewEvaluation::count();
        $readyForPlacement = MockInterviewEvaluation::where('recommendation', MockInterviewEvaluation::REC_READY_FOR_PLACEMENT)->count();
        $needsImprovement = MockInterviewEvaluation::where('recommendation', MockInterviewEvaluation::REC_NEEDS_IMPROVEMENT)->count();
        $reinterviewRequired = MockInterviewEvaluation::where('recommendation', MockInterviewEvaluation::REC_REINTERVIEW_REQUIRED)->count();

        $totalPlacementEligible = StudentPlacementEligibility::where('placement_eligible', true)->count();

        return [
            'total_interviewers' => $totalInterviewers,
            'active_interviewers' => $activeInterviewers,
            'total_slots' => $totalSlots,
            'available_slots' => $availableSlots,
            'total_bookings' => $totalBookings,
            'scheduled_bookings' => $scheduledBookings,
            'completed_bookings' => $completedBookings,
            'cancelled_bookings' => $cancelledBookings,
            'no_show_bookings' => $noShowBookings,
            'total_evaluations' => $totalEvaluations,
            'ready_for_placement' => $readyForPlacement,
            'needs_improvement' => $needsImprovement,
            'reinterview_required' => $reinterviewRequired,
            'total_placement_eligible' => $totalPlacementEligible,
        ];
    }
}
