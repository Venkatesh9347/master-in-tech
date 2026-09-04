<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\User;
use Illuminate\Http\Request;
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
        ]);

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
     * Pipeline Metrics (Admin only).
     */
    public function stats()
    {
        $total = Enquiry::count();
        $counts = [
            'total' => $total,
            'new' => Enquiry::where('status', Enquiry::STATUS_NEW)->count(),
            'contacted' => Enquiry::where('status', Enquiry::STATUS_CONTACTED)->count(),
            'demo_scheduled' => Enquiry::where('status', Enquiry::STATUS_DEMO_SCHEDULED)->count(),
            'demo_completed' => Enquiry::where('status', Enquiry::STATUS_DEMO_COMPLETED)->count(),
            'interested' => Enquiry::where('status', Enquiry::STATUS_INTERESTED)->count(),
            'follow_up' => Enquiry::where('status', Enquiry::STATUS_FOLLOW_UP)->count(),
            'admission_confirmed' => Enquiry::where('status', Enquiry::STATUS_ADMISSION_CONFIRMED)->count(),
            'enrolled' => Enquiry::where('status', Enquiry::STATUS_ENROLLED)->count(),
            'not_interested' => Enquiry::where('status', Enquiry::STATUS_NOT_INTERESTED)->count(),
            'no_response' => Enquiry::where('status', Enquiry::STATUS_NO_RESPONSE)->count(),
        ];

        return response()->json($counts);
    }

    /**
     * Show single enquiry details with notes (Admin only).
     */
    public function show(Enquiry $enquiry)
    {
        $enquiry->load(['user:id,name,email,role,phone', 'course', 'notes', 'enrolledUser']);

        return response()->json($enquiry);
    }

    /**
     * Update an enquiry status / metadata (Admin only).
     */
    public function update(Request $request, Enquiry $enquiry)
    {
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
        $validated = $request->validate([
            'course_id' => 'nullable|exists:courses,id',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'password' => 'nullable|string|min:8',
        ]);

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

        // Ensure user has student role and active status
        if ($user->role !== 'admin' && $user->role !== 'tutor') {
            $user->role = 'student';
            $user->status = 'active';
        }
        $user->save();

        // Create or activate enrollment
        $enrollment = CourseEnrollment::firstOrCreate(
            [
                'user_id' => $user->id,
                'course_id' => $course->id,
            ],
            [
                'enrolled_at' => now(),
                'status' => 'active',
            ]
        );

        // Update Enquiry lead status to ENROLLED
        $enquiry->update([
            'status' => Enquiry::STATUS_ENROLLED,
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
            'note' => "Student officially enrolled & granted LMS classroom access for '{$course->title}' by Administrator {$request->user()->name}.",
        ]);

        return response()->json([
            'message' => "Student {$user->name} has been enrolled in {$course->title} with active LMS access.",
            'enquiry' => $enquiry->fresh(['user', 'course', 'notes', 'enrolledUser']),
            'user' => $user,
            'enrollment' => $enrollment,
        ], 200);
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
}
