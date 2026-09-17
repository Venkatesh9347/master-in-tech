<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\BatchTransfer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentAccess;
use App\Services\Enrollment\EnrollmentPaymentGate;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminBatchController extends Controller
{
    /**
     * Get overview statistics for Batch Management.
     */
    public function stats()
    {
        $totalBatches = Batch::count();
        $activeBatches = Batch::where('status', 'ongoing')->count();
        $upcomingBatches = Batch::where('status', 'upcoming')->count();
        $completedBatches = Batch::where('status', 'completed')->count();
        $totalStudents = BatchStudent::where('status', 'active')->distinct('user_id')->count('user_id');

        return response()->json([
            'total_batches' => $totalBatches,
            'active_batches' => $activeBatches,
            'upcoming_batches' => $upcomingBatches,
            'completed_batches' => $completedBatches,
            'total_batch_students' => $totalStudents,
        ]);
    }

    /**
     * List all batches with search (including 6-digit date DDMMYY), course, tutor, and status filters.
     */
    public function index(Request $request)
    {
        $query = Batch::with([
            'course:id,title,slug,code,category,thumbnail',
            'tutor:id,name,email,avatar',
        ])->withCount(['activeBatchStudents', 'batchStudents']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->forCourse((int) $request->course_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->forStatus($request->status);
        }

        if ($request->filled('tutor_id') && $request->tutor_id !== 'all') {
            $query->forTutor((int) $request->tutor_id);
        }

        $batches = $query->orderBy('start_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request));

        return response()->json($batches);
    }

    /**
     * Lightweight cohort list for the CRM conversion picker. Read-only, minimal
     * fields only, so counsellors can assign a batch without full admin control.
     */
    public function crmBatchOptions(Request $request)
    {
        $query = Batch::query()->whereIn('status', ['upcoming', 'ongoing']);

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->forCourse((int) $request->course_id);
        }

        return response()->json($query->orderBy('start_date', 'desc')->get()->map(function (Batch $batch) {
            return [
                'id' => $batch->id,
                'name' => $batch->name,
                'code' => $batch->code,
                'course_id' => $batch->course_id,
                'status' => $batch->status,
                'start_date' => $batch->start_date,
                'schedule_time' => $batch->schedule_time,
                'max_students' => $batch->max_students,
            ];
        })->values());
    }

    /**
     * Create a new batch with automatic RIT(COURSE_CODE)BCDDMMYY generation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'course_id' => 'required|exists:courses,id',
            'tutor_id' => 'nullable|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|string|in:upcoming,ongoing,completed,cancelled',
            'schedule_type' => 'nullable|string|in:weekdays,weekends,daily,custom',
            'schedule_time' => 'nullable|string|max:255',
            'max_students' => 'nullable|integer|min:1',
            'meeting_link' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:2000',
            'code' => 'nullable|string|max:100|unique:batches,code',
        ]);

        $course = Course::findOrFail($validated['course_id']);

        // If custom code is explicitly enabled and provided, use it; otherwise automatically generate from course & start_date
        $isCustom = $request->boolean('is_custom_code');
        if ($isCustom && ! empty($validated['code'])) {
            $code = strtoupper(trim($validated['code']));
        } else {
            $code = Batch::generateBatchCode($course, $validated['start_date']);
        }

        // Determine default status if omitted
        $status = $validated['status'] ?? null;
        if (! $status) {
            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $today = Carbon::today();
            $status = $startDate->isFuture() ? 'upcoming' : 'ongoing';
        }

        $name = ! empty($validated['name']) ? trim($validated['name']) : $code;

        $batch = Batch::create([
            'name' => $name,
            'code' => $code,
            'course_id' => $validated['course_id'],
            'tutor_id' => $validated['tutor_id'] ?? null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'status' => $status,
            'schedule_type' => $validated['schedule_type'] ?? 'weekdays',
            'schedule_time' => $validated['schedule_time'] ?? null,
            'max_students' => $validated['max_students'] ?? null,
            'meeting_link' => $validated['meeting_link'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        $batch->load([
            'course:id,title,slug,code,category,thumbnail',
            'tutor:id,name,email,avatar',
        ])->loadCount(['activeBatchStudents', 'batchStudents']);

        AuditLog::log('created_batch', $batch, null, $batch->toArray());

        return response()->json([
            'message' => "Batch {$batch->code} created successfully.",
            'batch' => $batch,
        ], 201);
    }

    /**
     * Show a batch with student roster and transfer history.
     */
    public function show(Batch $batch)
    {
        $batch->load([
            'course:id,title,slug,code,category,thumbnail,instructor',
            'tutor:id,name,email,avatar,phone',
            'batchStudents.user:id,name,email,student_id,status,avatar,phone',
            'transfersFrom.student:id,name,email,student_id',
            'transfersFrom.toBatch:id,name,code',
            'transfersFrom.performer:id,name,email',
            'transfersTo.student:id,name,email,student_id',
            'transfersTo.fromBatch:id,name,code',
            'transfersTo.performer:id,name,email',
        ])->loadCount(['activeBatchStudents', 'batchStudents']);

        return response()->json($batch);
    }

    /**
     * Update a batch.
     */
    public function update(Request $request, Batch $batch)
    {
        $old = $batch->toArray();

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'course_id' => 'sometimes|required|exists:courses,id',
            'tutor_id' => 'nullable|exists:users,id',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|required|string|in:upcoming,ongoing,completed,cancelled',
            'schedule_type' => 'nullable|string|in:weekdays,weekends,daily,custom',
            'schedule_time' => 'nullable|string|max:255',
            'max_students' => 'nullable|integer|min:1',
            'meeting_link' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:2000',
            'code' => "sometimes|required|string|max:100|unique:batches,code,{$batch->id}",
        ]);

        $batch->update($validated);

        $batch->load([
            'course:id,title,slug,code,category,thumbnail',
            'tutor:id,name,email,avatar',
        ])->loadCount(['activeBatchStudents', 'batchStudents']);

        AuditLog::log('updated_batch', $batch, $old, $batch->toArray());

        return response()->json([
            'message' => 'Batch updated successfully.',
            'batch' => $batch,
        ]);
    }

    /**
     * Delete a batch.
     */
    public function destroy(Batch $batch)
    {
        $code = $batch->code;
        $old = $batch->toArray();
        $batch->delete();

        AuditLog::log('deleted_batch', null, $old, null);

        return response()->json([
            'message' => "Batch {$code} deleted successfully.",
        ]);
    }

    /**
     * Add / enroll a student into a batch.
     */
    public function addStudent(Request $request, Batch $batch)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'notes' => 'nullable|string|max:500',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        $userId = (int) $validated['user_id'];

        $overrideReason = EnrollmentPaymentGate::extractOverrideReason(
            $request->user(),
            $validated['override_reason'] ?? null
        );

        // B3 pay-before-classroom: resolve LMS access before cohort placement.
        $gate = EnrollmentPaymentGate::resolveStatus(
            $userId,
            (int) $batch->course_id,
            $request->user(),
            $overrideReason
        );
        $enrollmentActive = $gate['status'] === EnrollmentPaymentGate::STATUS_ACTIVE;

        // Check if student is already actively in this batch
        $existing = BatchStudent::where('batch_id', $batch->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Student is already actively enrolled in this batch.',
            ], 422);
        }

        // Check capacity if set
        if ($batch->max_students) {
            $activeCount = BatchStudent::where('batch_id', $batch->id)
                ->where('status', 'active')
                ->count();
            if ($activeCount >= $batch->max_students) {
                return response()->json([
                    'message' => "Batch is at full capacity ({$batch->max_students} students).",
                ], 422);
            }
        }

        // 1. Ensure student enrollment reflects verified payment state.
        // Without payment/override the enrollment stays pending and cohort
        // placement is deferred (no active batch membership without LMS access).
        $previousEnrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $batch->course_id)
            ->first();
        $previousStatus = $previousEnrollment?->status;

        if ($previousEnrollment && in_array($previousEnrollment->status, ['active', 'completed'], true)) {
            $enrollmentActive = true;
        } elseif ($previousEnrollment && $enrollmentActive) {
            $previousEnrollment->update(['status' => 'active']);
        } elseif (! $previousEnrollment) {
            CourseEnrollment::create([
                'user_id' => $userId,
                'course_id' => $batch->course_id,
                'enrolled_at' => now(),
                'status' => $gate['status'],
                'progress_percentage' => 0.00,
            ]);
        }

        if ($gate['via_override']) {
            $enrollmentModel = CourseEnrollment::where('user_id', $userId)
                ->where('course_id', $batch->course_id)
                ->first();
            EnrollmentAccess::logOverride(
                $request->user(),
                $userId,
                (int) $batch->course_id,
                $previousStatus,
                $enrollmentModel?->status ?? $gate['status'],
                (string) $overrideReason,
                $gate['payment_verified'],
                $enrollmentModel
            );
        }

        if (! $enrollmentActive) {
            AuditLog::log('assigned_batch_student_deferred', $batch, null, ['batch_id' => $batch->id, 'batch_code' => $batch->code, 'user_id' => $userId, 'reason' => 'pending_verified_payment']);

            return response()->json([
                'message' => 'Student pre-admitted pending verified payment. Cohort placement deferred until payment is verified.',
                'payment_required' => true,
                'enrollment_status' => $gate['status'],
                'membership' => null,
            ], 201);
        }

        // 2. Create batch membership
        $membership = BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $userId,
            'status' => 'active',
            'joined_at' => now(),
            'notes' => $validated['notes'] ?? null,
        ]);

        // 3. Log batch transfer/membership event
        BatchTransfer::create([
            'user_id' => $userId,
            'from_batch_id' => null,
            'to_batch_id' => $batch->id,
            'action_type' => 'enrolled',
            'reason' => $validated['notes'] ?? 'Enrolled into cohort',
            'performed_by' => auth()->id(),
        ]);

        $membership->load('user:id,name,email,student_id,status,avatar');

        AuditLog::log('assigned_batch_student', $batch, null, ['batch_id' => $batch->id, 'batch_code' => $batch->code, 'user_id' => $userId]);

        // F1: membership row is committed (no surrounding transaction here).
        NotificationService::batchMembershipChanged('assigned', $membership->fresh());

        return response()->json([
            'message' => 'Student enrolled into batch successfully.',
            'membership' => $membership,
        ], 201);
    }

    /**
     * Transfer a student from current batch to another batch.
     * Preserves complete historical membership in source batch.
     */
    public function transferStudent(Request $request, Batch $batch, User $user)
    {
        $validated = $request->validate([
            'to_batch_id' => "required|exists:batches,id|different:{$batch->id}",
            'reason' => 'required|string|max:1000',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        $toBatch = Batch::findOrFail($validated['to_batch_id']);

        // Check if user is actively in current batch
        $currentMembership = BatchStudent::where('batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $currentMembership) {
            return response()->json([
                'message' => 'Student is not actively enrolled in this batch.',
            ], 422);
        }

        // Check if user already actively in destination batch
        $destMembership = BatchStudent::where('batch_id', $toBatch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($destMembership) {
            return response()->json([
                'message' => 'Student is already actively enrolled in destination batch.',
            ], 422);
        }

        // HIGH-3: reject transfer into completed/cancelled destination batches
        if (in_array($toBatch->status, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => "Cannot transfer a student into a {$toBatch->status} batch.",
            ], 422);
        }

        // HIGH-3: reject transfer when the destination batch is at full capacity
        if ($toBatch->max_students) {
            $destActiveCount = BatchStudent::where('batch_id', $toBatch->id)
                ->where('status', 'active')
                ->count();
            if ($destActiveCount >= $toBatch->max_students) {
                return response()->json([
                    'message' => "Destination batch is at full capacity ({$toBatch->max_students} students).",
                ], 422);
            }
        }

        // B3 pay-before-classroom: cross-course transfers require verified
        // payment for the target course (or an explicit admin override),
        // unless the student already holds active access there.
        $targetEnrollment = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $toBatch->course_id)
            ->first();
        $alreadyActiveTarget = $targetEnrollment && in_array($targetEnrollment->status, ['active', 'completed'], true);

        $transferOverride = EnrollmentPaymentGate::extractOverrideReason(
            $request->user(),
            $validated['override_reason'] ?? null
        );

        if (! $alreadyActiveTarget
            && ! EnrollmentPaymentGate::canActivate((int) $user->id, (int) $toBatch->course_id, $request->user(), $transferOverride)) {
            return response()->json([
                'message' => 'Verified payment for the destination course is required before transfer.',
                'payment_required' => true,
            ], 422);
        }

        $transferViaOverride = $transferOverride !== null
            && ! $alreadyActiveTarget
            && ! EnrollmentPaymentGate::hasVerifiedPaidPayment((int) $user->id, (int) $toBatch->course_id);

        DB::transaction(function () use ($batch, $toBatch, $user, $currentMembership, $validated, $transferOverride, $transferViaOverride) {
            // 1. Mark source batch membership as 'transferred' with left_at timestamp
            $currentMembership->update([
                'status' => 'transferred',
                'left_at' => now(),
                'notes' => 'Transferred to ' . $toBatch->code . '. Reason: ' . $validated['reason'],
            ]);

            // 2. Create active membership in destination batch
            BatchStudent::create([
                'batch_id' => $toBatch->id,
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
                'notes' => 'Transferred from ' . $batch->code . '. Reason: ' . $validated['reason'],
            ]);

            // 3. Ensure student enrollment in target course reflects payment state.
            // Already-active access is preserved; otherwise activate only with
            // verified payment/override (guaranteed by the pre-check above).
            $existingTarget = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $toBatch->course_id)
                ->first();

            if ($existingTarget && in_array($existingTarget->status, ['active', 'completed'], true)) {
                // Preserve existing access; nothing to change.
            } elseif ($existingTarget) {
                $existingTarget->update(['status' => 'active']);
            } else {
                CourseEnrollment::create([
                    'user_id' => $user->id,
                    'course_id' => $toBatch->course_id,
                    'enrolled_at' => now(),
                    'status' => 'active',
                    'progress_percentage' => 0.00,
                ]);
            }

            // 4. Log complete transfer record
            BatchTransfer::create([
                'user_id' => $user->id,
                'from_batch_id' => $batch->id,
                'to_batch_id' => $toBatch->id,
                'action_type' => 'transferred',
                'reason' => $validated['reason'],
                'performed_by' => auth()->id(),
            ]);
        });

        AuditLog::log('transferred_batch_student', $batch, ['from_batch' => $batch->code, 'student' => $user->name], ['to_batch' => $toBatch->code, 'reason' => $validated['reason']]);

        // F1: transfer transaction committed above; notify on the fresh
        // destination membership so stale state can never match.
        $newMembership = BatchStudent::where('batch_id', $toBatch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($newMembership) {
            NotificationService::batchMembershipChanged('transferred', $newMembership);
        }

        if ($transferViaOverride) {
            $targetEnrollment = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $toBatch->course_id)
                ->first();
            EnrollmentAccess::logOverride(
                $request->user(),
                (int) $user->id,
                (int) $toBatch->course_id,
                null,
                $targetEnrollment?->status ?? 'active',
                (string) $transferOverride,
                false,
                $targetEnrollment
            );
        }

        return response()->json([
            'message' => "Student {$user->name} transferred from {$batch->code} to {$toBatch->code} successfully.",
        ]);
    }

    /**
     * Mark a student as discontinued from a batch.
     */
    public function discontinueStudent(Request $request, Batch $batch, User $user)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $membership = BatchStudent::where('batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'Student is not actively enrolled in this batch.',
            ], 422);
        }

        DB::transaction(function () use ($batch, $user, $membership, $validated) {
            // Mark membership as discontinued
            $membership->update([
                'status' => 'discontinued',
                'left_at' => now(),
                'discontinued_at' => now(),
                'discontinuation_reason' => $validated['reason'],
            ]);

            // Log discontinuation
            BatchTransfer::create([
                'user_id' => $user->id,
                'from_batch_id' => $batch->id,
                'to_batch_id' => null,
                'action_type' => 'discontinued',
                'reason' => $validated['reason'],
                'performed_by' => auth()->id(),
            ]);
        });

        AuditLog::log('discontinued_batch_student', $batch, ['batch' => $batch->code, 'student' => $user->name], ['reason' => $validated['reason']]);

        // F1: discontinuation transaction committed above.
        NotificationService::batchMembershipChanged('discontinued', $membership->fresh());

        return response()->json([
            'message' => "Student {$user->name} marked as discontinued from {$batch->code}.",
        ]);
    }

    /**
     * Rejoin a student into this batch or another batch.
     */
    public function rejoinStudent(Request $request, Batch $batch, User $user)
    {
        $validated = $request->validate([
            'target_batch_id' => 'nullable|exists:batches,id',
            'reason' => 'nullable|string|max:1000',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        $targetBatchId = $validated['target_batch_id'] ?? $batch->id;
        $targetBatch = Batch::findOrFail($targetBatchId);

        // HIGH-3: reject rejoin into completed/cancelled batches
        if (in_array($targetBatch->status, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => "Cannot rejoin a student into a {$targetBatch->status} batch.",
            ], 422);
        }

        // HIGH-3: reject rejoin when the target batch is at full capacity
        if ($targetBatch->max_students && $targetBatch->id !== $batch->id) {
            $targetActiveCount = BatchStudent::where('batch_id', $targetBatch->id)
                ->where('status', 'active')
                ->count();
            if ($targetActiveCount >= $targetBatch->max_students) {
                return response()->json([
                    'message' => "Target batch is at full capacity ({$targetBatch->max_students} students).",
                ], 422);
            }
        }

        // Check if student already active in target batch
        $activeInTarget = BatchStudent::where('batch_id', $targetBatch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($activeInTarget) {
            return response()->json([
                'message' => 'Student is already actively enrolled in the target batch.',
            ], 422);
        }

        // B3 pay-before-classroom: rejoin requires verified payment for the
        // target course (or an explicit admin override), unless the student
        // already holds active access there.
        $rejoinTarget = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $targetBatch->course_id)
            ->first();
        $alreadyActiveRejoin = $rejoinTarget && in_array($rejoinTarget->status, ['active', 'completed'], true);

        $rejoinOverride = EnrollmentPaymentGate::extractOverrideReason(
            $request->user(),
            $validated['override_reason'] ?? null
        );

        if (! $alreadyActiveRejoin
            && ! EnrollmentPaymentGate::canActivate((int) $user->id, (int) $targetBatch->course_id, $request->user(), $rejoinOverride)) {
            return response()->json([
                'message' => 'Verified payment for the target course is required before rejoin.',
                'payment_required' => true,
            ], 422);
        }

        $rejoinViaOverride = $rejoinOverride !== null
            && ! $alreadyActiveRejoin
            && ! EnrollmentPaymentGate::hasVerifiedPaidPayment((int) $user->id, (int) $targetBatch->course_id);

        DB::transaction(function () use ($batch, $targetBatch, $user, $validated) {
            if ($targetBatch->id === $batch->id) {
                // Rejoining the same batch: reactivate membership
                $membership = BatchStudent::where('batch_id', $batch->id)
                    ->where('user_id', $user->id)
                    ->where('status', 'discontinued')
                    ->latest()
                    ->first();

                if ($membership) {
                    $membership->update([
                        'status' => 'active',
                        'left_at' => null,
                        'discontinued_at' => null,
                        'notes' => 'Rejoined on ' . now()->toDateString() . '. Note: ' . ($validated['reason'] ?? 'None'),
                    ]);
                } else {
                    BatchStudent::create([
                        'batch_id' => $batch->id,
                        'user_id' => $user->id,
                        'status' => 'active',
                        'joined_at' => now(),
                        'notes' => 'Rejoined: ' . ($validated['reason'] ?? ''),
                    ]);
                }
            } else {
                // CRITICAL-3: reuse an existing target membership (any status) if
                // present, so a prior transfer/rejoin exchange cannot create
                // duplicate active memberships for the same (user, batch).
                $existingTarget = BatchStudent::where('batch_id', $targetBatch->id)
                    ->where('user_id', $user->id)
                    ->latest()
                    ->first();

                if ($existingTarget) {
                    $existingTarget->update([
                        'status' => 'active',
                        'joined_at' => now(),
                        'left_at' => null,
                        'discontinued_at' => null,
                        'notes' => 'Rejoined into new cohort from ' . $batch->code . '. Note: ' . ($validated['reason'] ?? ''),
                    ]);
                } else {
                    BatchStudent::create([
                        'batch_id' => $targetBatch->id,
                        'user_id' => $user->id,
                        'status' => 'active',
                        'joined_at' => now(),
                        'notes' => 'Rejoined into new cohort from ' . $batch->code . '. Note: ' . ($validated['reason'] ?? ''),
                    ]);
                }
            }

            // Ensure course enrollment reflects payment state (pre-check above
            // guarantees activation is allowed when reaching here).
            $existingRejoin = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $targetBatch->course_id)
                ->first();

            if ($existingRejoin && in_array($existingRejoin->status, ['active', 'completed'], true)) {
                // Preserve existing access.
            } elseif ($existingRejoin) {
                $existingRejoin->update(['status' => 'active']);
            } else {
                CourseEnrollment::create([
                    'user_id' => $user->id,
                    'course_id' => $targetBatch->course_id,
                    'enrolled_at' => now(),
                    'status' => 'active',
                    'progress_percentage' => 0.00,
                ]);
            }

            // Log rejoin event
            BatchTransfer::create([
                'user_id' => $user->id,
                'from_batch_id' => $batch->id,
                'to_batch_id' => $targetBatch->id,
                'action_type' => 'rejoined',
                'reason' => $validated['reason'] ?? 'Rejoined cohort',
                'performed_by' => auth()->id(),
            ]);
        });

        AuditLog::log('rejoined_batch_student', $targetBatch, null, ['batch' => $targetBatch->code, 'student' => $user->name, 'reason' => $validated['reason'] ?? 'Rejoined']);

        if ($rejoinViaOverride) {
            $rejoinEnrollment = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $targetBatch->course_id)
                ->first();
            EnrollmentAccess::logOverride(
                $request->user(),
                (int) $user->id,
                (int) $targetBatch->course_id,
                $rejoinTarget?->status,
                $rejoinEnrollment?->status ?? 'active',
                (string) $rejoinOverride,
                false,
                $rejoinEnrollment
            );
        }

        return response()->json([
            'message' => "Student {$user->name} successfully rejoined into batch {$targetBatch->code}.",
        ]);
    }

    /**
     * Remove a student from a batch.
     *
     * Only the active membership is deactivated; historical membership records
     * (transferred / discontinued / completed) are preserved for the audit trail.
     */
    public function removeStudent(Batch $batch, User $user)
    {
        DB::transaction(function () use ($batch, $user) {
            $active = BatchStudent::where('batch_id', $batch->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->latest()
                ->first();

            if ($active) {
                $active->update([
                    'status' => 'removed',
                    'left_at' => now(),
                    'notes' => ($active->notes ? trim($active->notes) . ' ' : '') . 'Removed from batch on ' . now()->toDateTimeString(),
                ]);
            }
        });

        AuditLog::log('removed_batch_student', $batch, ['batch_id' => $batch->id, 'batch_code' => $batch->code, 'user_id' => $user->id, 'student' => $user->name], null);

        return response()->json([
            'message' => "Student {$user->name} removed from batch {$batch->code}.",
        ]);
    }

    /**
     * Get platform-wide or batch-specific transfer & lifecycle audit trail.
     */
    public function history(Request $request)
    {
        $query = BatchTransfer::with([
            'student:id,name,email,student_id,avatar',
            'fromBatch:id,name,code',
            'toBatch:id,name,code',
            'performer:id,name,email',
        ]);

        if ($request->filled('batch_id')) {
            $batchId = (int) $request->batch_id;
            $query->where(function ($q) use ($batchId) {
                $q->where('from_batch_id', $batchId)
                    ->orWhere('to_batch_id', $batchId);
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('action_type') && $request->action_type !== 'all') {
            $query->where('action_type', $request->action_type);
        }

        $history = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request));

        return response()->json($history);
    }
}
