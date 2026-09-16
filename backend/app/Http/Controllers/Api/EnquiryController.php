<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\BatchTransfer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\User;
use App\Services\Enrollment\EnrollmentAccess;
use App\Services\Enrollment\EnrollmentPaymentGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnquiryController extends Controller
{
    /**
     * Store a new course enquiry / demo request (supports both public visitors and authenticated students).
     */
    public function store(Request $request)
    {
        $authUser = $this->getAuthenticatedUser($request);

        if ($authUser) {
            $validated = $request->validate([
                'course_id' => 'nullable|exists:courses,id',
                'course_title' => 'nullable|string|max:255',
                'preferred_time' => 'nullable|string|max:100',
                'message' => 'nullable|string|max:2000',
            ]);

            $name = $authUser->name;
            $email = $authUser->email;
            $phone = ! empty($authUser->phone) ? $authUser->phone : ($request->input('phone') ?: 'Registered Student');
            $userId = $authUser->id; // Never trust client-provided user_id
        } else {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:30',
                'course_id' => 'nullable|exists:courses,id',
                'course_title' => 'nullable|string|max:255',
                'preferred_time' => 'nullable|string|max:100',
                'message' => 'nullable|string|max:2000',
            ]);

            $name = $validated['name'];
            $email = $validated['email'];
            $phone = $validated['phone'];
            $userId = null; // Public visitors have no user_id
        }

        [$courseId, $courseTitle] = $this->resolveExistingCatalogCourse(
            isset($validated['course_id']) ? (int) $validated['course_id'] : null,
            $validated['course_title'] ?? null
        );

        // Duplicate active enquiry protection
        $existingQuery = Enquiry::query();
        if ($userId) {
            $existingQuery->where(function ($q) use ($userId, $email) {
                $q->where('user_id', $userId)
                    ->orWhere('email', $email);
            });
        } else {
            $existingQuery->where('email', $email);
        }

        if ($courseId) {
            $existingQuery->where('course_id', $courseId);
        } else {
            $existingQuery->whereNull('course_id');
        }

        $inactiveStatuses = [
            Enquiry::STATUS_CLOSED,
            Enquiry::STATUS_NOT_INTERESTED,
            Enquiry::STATUS_NO_RESPONSE,
        ];

        $existingEnquiry = $existingQuery->whereNotIn('status', $inactiveStatuses)->latest()->first();

        if ($existingEnquiry) {
            return response()->json([
                'message' => 'An active enquiry already exists for this course.',
                'enquiry' => $existingEnquiry->load(['course', 'user']),
                'already_exists' => true,
            ], 200);
        }

        $enquiry = Enquiry::create([
            'user_id' => $userId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'course_id' => $courseId,
            'course_title' => $courseTitle,
            'preferred_time' => $validated['preferred_time'] ?? null,
            'message' => $validated['message'] ?? null,
            'status' => Enquiry::STATUS_NEW,
        ]);

        // Auto create initial enquiry note
        $noteText = $authUser
            ? "Student enquiry submitted for '{$enquiry->course_title}' by registered student {$authUser->name} ({$authUser->email}). Preferred slot: " . ($enquiry->preferred_time ?? 'Flexible')
            : "Public demo enquiry submitted for '{$enquiry->course_title}'. Preferred slot: " . ($enquiry->preferred_time ?? 'Flexible');

        EnquiryNote::create([
            'enquiry_id' => $enquiry->id,
            'user_id' => $userId,
            'user_name' => 'System',
            'note' => $noteText,
        ]);

        return response()->json([
            'message' => 'Thank you for your enquiry! Our academic admissions advisor will contact you shortly.',
            'enquiry' => $enquiry->load(['course', 'user']),
        ], 201);
    }

    /**
     * Check if authenticated user has an active enquiry for a course.
     */
    public function checkCourseEnquiry(Request $request, $courseId)
    {
        $user = $this->getAuthenticatedUser($request);
        if (! $user) {
            return response()->json([
                'has_enquiry' => false,
                'enquiry' => null,
            ]);
        }

        $inactiveStatuses = [
            Enquiry::STATUS_CLOSED,
            Enquiry::STATUS_NOT_INTERESTED,
            Enquiry::STATUS_NO_RESPONSE,
        ];

        $enquiry = Enquiry::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('email', $user->email);
            })
            ->where('course_id', $courseId)
            ->whereNotIn('status', $inactiveStatuses)
            ->latest()
            ->first();

        return response()->json([
            'has_enquiry' => (bool) $enquiry,
            'enquiry' => $enquiry ? $enquiry->load(['course', 'user']) : null,
        ]);
    }

    /**
     * List all course enquiries / demo leads (Admin only).
     */
    public function index(Request $request)
    {
        $query = Enquiry::with([
            'user:id,name,email,role,phone',
            'course:id,title,category',
            'notes',
            'enrolledUser:id,name,email',
        ])->visibleTo($request->user());

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $filterCourseId = (int) $request->course_id;
            $filterCourse = Course::find($filterCourseId);
            $query->where(function ($q) use ($filterCourseId, $filterCourse) {
                $q->where('course_id', $filterCourseId);
                if ($filterCourse) {
                    $q->orWhereRaw('LOWER(course_title) = ?', [mb_strtolower($filterCourse->title)]);
                }
            });
        }

        if ($request->filled('assigned_agent') && $request->assigned_agent !== 'all') {
            $query->where('assigned_agent', $request->assigned_agent);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('course_title', 'like', "%{$search}%");
            });
        }

        $enquiries = $query->orderBy('created_at', 'desc');

        $limit = $this->limitCap($request);
        if ($limit !== null) {
            $enquiries->limit($limit);
        }

        return response()->json($enquiries->get());
    }

    /**
     * Pipeline Metrics (scoped for CRM staff).
     */
    public function stats(Request $request)
    {
        $leads = Enquiry::visibleTo($request->user());
        $total = (clone $leads)->count();
        $counts = [
            'total' => $total,
            'new' => (clone $leads)->where('status', Enquiry::STATUS_NEW)->count(),
            'contacted' => (clone $leads)->where('status', Enquiry::STATUS_CONTACTED)->count(),
            'demo_scheduled' => (clone $leads)->where('status', Enquiry::STATUS_DEMO_SCHEDULED)->count(),
            'demo_completed' => (clone $leads)->where('status', Enquiry::STATUS_DEMO_COMPLETED)->count(),
            'interested' => (clone $leads)->where('status', Enquiry::STATUS_INTERESTED)->count(),
            'follow_up' => (clone $leads)->where('status', Enquiry::STATUS_FOLLOW_UP)->count(),
            'admission_confirmed' => (clone $leads)->where('status', Enquiry::STATUS_ADMISSION_CONFIRMED)->count(),
            'enrolled' => (clone $leads)->where('status', Enquiry::STATUS_ENROLLED)->count(),
            'not_interested' => (clone $leads)->where('status', Enquiry::STATUS_NOT_INTERESTED)->count(),
            'no_response' => (clone $leads)->where('status', Enquiry::STATUS_NO_RESPONSE)->count(),
        ];

        return response()->json($counts);
    }

    /**
     * Show single enquiry details with notes (Admin only).
     */
    public function show(Request $request, Enquiry $enquiry)
    {
        $this->denyUnlessLeadVisible($request, $enquiry);
        $enquiry->load(['user:id,name,email,role,phone', 'course', 'notes', 'enrolledUser']);

        return response()->json($enquiry);
    }

    /**
     * Update an enquiry status / metadata (Admin only).
     */
    public function update(Request $request, Enquiry $enquiry)
    {
        $this->denyUnlessLeadVisible($request, $enquiry);
        $validStatuses = implode(',', Enquiry::PIPELINE_STATUSES);

        $validated = $request->validate([
            'status' => "nullable|string|in:{$validStatuses}",
            'demo_date' => 'nullable|date',
            'demo_time' => 'nullable|string|max:50',
            'demo_outcome' => 'nullable|string|max:255',
            'assigned_agent' => 'nullable|string|max:255',
            'course_id' => 'nullable|exists:courses,id',
            'message' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        // B3-4 legacy parity: scoped staff share the new-CRM reassignment and
        // admission-state rules. They may only claim/release leads for
        // themselves and may not confirm admission via the legacy path
        // (admission flows through payment-gated convert/enroll).
        if ($request->user() && $request->user()->hasScopedCrmAccess()) {
            if (array_key_exists('assigned_agent', $validated) && $validated['assigned_agent'] !== null) {
                $target = trim((string) $validated['assigned_agent']);
                $ownName = trim((string) $request->user()->name);
                if ($target !== '' && $target !== $ownName) {
                    abort(403, 'You can only assign leads to yourself.');
                }
            }

            if (array_key_exists('status', $validated)
                && in_array($validated['status'], [Enquiry::STATUS_ADMISSION_CONFIRMED, Enquiry::STATUS_ENROLLED, Enquiry::STATUS_CONVERTED], true)) {
                abort(403, 'Only administrators can confirm admission via this path.');
            }
        }

        $oldStatus = $enquiry->status;

        if (! empty($validated['course_id']) && $validated['course_id'] != $enquiry->course_id) {
            $course = Course::find($validated['course_id']);
            if ($course) {
                $enquiry->course_id = $course->id;
                $enquiry->course_title = $course->title;
            }
        }

        $enquiry->fill(collect($validated)->except(['note'])->toArray());
        $enquiry->save();

        // If a note was included with the update, create a note entry
        if (! empty($validated['note'])) {
            EnquiryNote::create([
                'enquiry_id' => $enquiry->id,
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'note' => $validated['note'],
            ]);
        } elseif (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $statusLabel = strtoupper(str_replace('_', ' ', $validated['status']));
            EnquiryNote::create([
                'enquiry_id' => $enquiry->id,
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'note' => "Status updated from '{$oldStatus}' to '{$statusLabel}'.",
            ]);
        }

        return response()->json([
            'message' => 'Enquiry updated successfully.',
            'enquiry' => $enquiry->fresh(['user', 'course', 'notes', 'enrolledUser']),
        ]);
    }

    /**
     * Add a follow-up note to an enquiry (Admin only).
     */
    public function addNote(Request $request, Enquiry $enquiry)
    {
        $this->denyUnlessLeadVisible($request, $enquiry);
        $validated = $request->validate([
            'note' => 'required|string|max:2000',
        ]);

        $note = EnquiryNote::create([
            'enquiry_id' => $enquiry->id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'note' => $validated['note'],
        ]);

        return response()->json([
            'message' => 'Follow-up note logged successfully.',
            'note' => $note,
            'enquiry' => $enquiry->fresh(['user', 'course', 'notes', 'enrolledUser']),
        ], 201);
    }

    /**
     * Enroll student from lead pipeline (Admin only).
     */
    public function enroll(Request $request, Enquiry $enquiry)
    {
        $this->denyUnlessLeadVisible($request, $enquiry);
        $validated = $request->validate([
            'course_id' => 'nullable|exists:courses,id',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'password' => 'nullable|string|min:8',
            'batch_id' => 'nullable|exists:batches,id',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        // B3-2: scoped CRM staff can never use a payment override.
        $overrideReason = EnrollmentPaymentGate::extractOverrideReason(
            $request->user(),
            $validated['override_reason'] ?? null
        );

        $courseId = $validated['course_id'] ?? $enquiry->course_id;

        if (! $courseId) {
            return response()->json([
                'message' => 'No course specified for enrollment. Please assign a course first.',
            ], 422);
        }

        $course = Course::findOrFail($courseId);

        $studentEmail = $validated['email'] ?? $enquiry->email;
        $studentName = $validated['name'] ?? $enquiry->name;
        $deniedPasswords = ['password', 'password123', '123456', 'admin123', 'password1', 'qwerty123', 'letmein', 'welcome123', 'changeme', 'masterin2026', 'masterpartner', 'master@2026'];
        $providedPassword = $validated['password'] ?? null;
        $studentPassword = User::generateUnusablePassword();
        if (is_string($providedPassword) && strlen($providedPassword) >= 8
            && ! in_array(strtolower($providedPassword), $deniedPasswords, true)) {
            $studentPassword = $providedPassword;
        }

        // Find or provision student account (Google / mobile OTP is the student login path).
        // The whole admission write set commits atomically: a failure after
        // user provisioning must not leave a half-enrolled student behind.
        try {
            DB::beginTransaction();

            $user = $this->provisionEnrolledStudent($validated, $enquiry, $course, $studentEmail, $studentName, $studentPassword);

            // B3 pay-before-classroom: active access requires verified payment
            // for this exact user + course, or an explicit admin override.
            $gate = EnrollmentPaymentGate::resolveStatus(
                (int) $user->id,
                (int) $course->id,
                $request->user(),
                $overrideReason
            );
            $viaOverride = $gate['via_override'];
            $paymentVerified = $gate['payment_verified'];

            $existingEnrollment = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();
            $previousEnrollmentStatus = $existingEnrollment?->status;

            if ($existingEnrollment && in_array($existingEnrollment->status, ['active', 'completed'], true)) {
                $enrollment = $existingEnrollment;
                $viaOverride = false;
            } elseif ($existingEnrollment) {
                if ($gate['status'] === EnrollmentPaymentGate::STATUS_ACTIVE) {
                    $existingEnrollment->update(['status' => 'active']);
                    $enrollment = $existingEnrollment->fresh();
                } else {
                    $enrollment = $existingEnrollment;
                }
            } else {
                $enrollment = CourseEnrollment::create([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'enrolled_at' => now(),
                    'status' => $gate['status'],
                ]);
            }

            $enrollmentActive = $enrollment->status === EnrollmentPaymentGate::STATUS_ACTIVE;

            // Cohort placement only with active LMS access; otherwise deferred.
            if ($enrollmentActive) {
                $this->assignEnquiryBatch($request, $validated, $course, $user);
            }

            // Update Enquiry lead status: enrolled only with active access,
            // otherwise payment_pending (admitted, awaiting verified payment).
            $enquiry->update([
                'status' => $enrollmentActive ? Enquiry::STATUS_ENROLLED : Enquiry::STATUS_PAYMENT_PENDING,
                'course_id' => $course->id,
                'course_title' => $course->title,
                'enrolled_user_id' => $user->id,
                'enrolled_at' => now(),
            ]);

            // Log automatic audit follow-up note
            EnquiryNote::create([
                'enquiry_id' => $enquiry->id,
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'note' => $enrollmentActive
                    ? "Student officially enrolled & granted LMS classroom access for '{$course->title}' by Administrator {$request->user()->name}."
                    : "Student pre-admitted to '{$course->title}' by {$request->user()->name}; LMS access pending verified payment.",
            ]);

            if ($viaOverride) {
                EnrollmentAccess::logOverride(
                    $request->user(),
                    (int) $user->id,
                    (int) $course->id,
                    $previousEnrollmentStatus,
                    $enrollment->status,
                    (string) $overrideReason,
                    $paymentVerified,
                    $enrollment
                );
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('enquiry.enroll.failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'enquiry_id' => $enquiry->id ?? null,
                'course_id' => $course->id ?? null,
                'user_id' => $user->id ?? null,
                'enrollment_id' => $enrollment->id ?? null,
            ]);

            return response()->json([
                'message' => 'Failed to enroll student. Please try again.',
            ], 500);
        }

        if ($enrollment->status !== EnrollmentPaymentGate::STATUS_ACTIVE) {
            return response()->json([
                'message' => "Student {$user->name} has been pre-admitted to {$course->title} pending verified payment. LMS access remains blocked until payment is verified.",
                'payment_required' => true,
                'enrollment_status' => $enrollment->status,
                'enquiry' => $enquiry->fresh(['user', 'course', 'notes', 'enrolledUser']),
                'user' => $user,
                'enrollment' => $enrollment,
            ], 200);
        }

        return response()->json([
            'message' => "Student {$user->name} has been enrolled in {$course->title} with active LMS access.",
            'payment_verified' => $paymentVerified ?? false,
            'via_override' => $viaOverride ?? false,
            'enquiry' => $enquiry->fresh(['user', 'course', 'notes', 'enrolledUser']),
            'user' => $user,
            'enrollment' => $enrollment,
        ], 200);
    }

    private function provisionEnrolledStudent(array $validated, Enquiry $enquiry, Course $course, string $studentEmail, string $studentName, string $studentPassword): User
    {
        // Find or provision student account (Google / mobile OTP is the student login path)
        $user = User::firstOrCreate(
            ['email' => $studentEmail],
            [
                'name' => $studentName,
                'password' => $studentPassword,
                'status' => 'active',
                'phone' => $enquiry->phone,
            ]
        );

        if (! $user->student_id) {
            $user->student_id = 'STU-'.(1000 + $user->id);
        }

        // B3-4: preserve existing staff roles. Never demote admin/super_admin,
        // tutor/faculty, counsellor/telecaller/course_advisor (or
        // company/recruiter) into student.
        $staffRoles = ['admin', 'super_admin', 'tutor', 'faculty', 'counsellor', 'telecaller', 'course_advisor', 'company', 'recruiter'];
        if (! in_array($user->role, $staffRoles, true)) {
            $user->role = 'student';
            $user->status = 'active';
        }
        $user->save();

        return $user;
    }

    private function assignEnquiryBatch(Request $request, array $validated, Course $course, User $user): void
    {
        // Optionally assign the student to a cohort batch (audited), consistent
        // with the CRM conversion flow. Batch history is never deleted.
        if (empty($validated['batch_id'])) {
            return;
        }

        $batch = Batch::find($validated['batch_id']);
        if (! $batch || (int) $batch->course_id !== $course->id) {
            return;
        }

        $existingBatchStudent = BatchStudent::where('batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $existingBatchStudent) {
            BatchStudent::create([
                'batch_id' => $batch->id,
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
                'notes' => 'Assigned during enquiry-to-enrollment conversion',
            ]);

            BatchTransfer::create([
                'user_id' => $user->id,
                'from_batch_id' => null,
                'to_batch_id' => $batch->id,
                'action_type' => 'enrolled',
                'reason' => 'Enquiry lead enrolled with cohort assignment',
                'performed_by' => $request->user()->id,
            ]);
        } elseif ($existingBatchStudent->status !== 'active') {
            $existingBatchStudent->update([
                'status' => 'active',
                'left_at' => null,
                'discontinued_at' => null,
            ]);
        }
    }

    private function getAuthenticatedUser(Request $request)
    {
        return $request->user() ?? $request->user('sanctum') ?? auth('sanctum')->user() ?? auth()->user();
    }

    /**
     * Map public enquiry selections onto an existing catalog course.
     * Never creates a course; unmatched titles are stored as-is with a null course_id.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveExistingCatalogCourse(?int $courseId, ?string $courseTitle): array
    {
        $course = null;

        if (! empty($courseId)) {
            $course = Course::find($courseId);
        }

        if (! $course && ! empty($courseTitle)) {
            $normalized = trim($courseTitle);
            $course = Course::query()
                ->where(function ($q) use ($normalized) {
                    $q->whereRaw('LOWER(title) = ?', [mb_strtolower($normalized)])
                        ->orWhere('slug', Str::slug($normalized));
                })
                ->first();
        }

        if ($course) {
            return [$course->id, $course->title];
        }

        return [null, $courseTitle];
    }

    /**
     * Record-level gate: scoped CRM staff may only touch own + unassigned leads.
     */
    private function denyUnlessLeadVisible(Request $request, Enquiry $lead): void
    {
        if (! $lead->isVisibleTo($request->user())) {
            abort(403, 'You do not have access to this lead.');
        }
    }
}
