<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\ClassSession;
use App\Models\ClassSessionAttendance;
use App\Models\CourseEnrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClassSessionController extends Controller
{
    /**
     * Get all class sessions for enrolled courses with optional course filter.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->isCompany()) {
            return response()->json([
                'message' => 'Corporate partners cannot access student classroom sessions.',
            ], 403);
        }

        ClassSession::syncRealtimeStatuses();
        $query = ClassSession::whereIn('course_id', function ($sq) use ($user) {
                $sq->select('course_id')->from('course_enrollments')->where('user_id', $user->id)->where('status', 'active');
            })
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url',
            ])
            ->with([
                'course:id,title,category,thumbnail,slug',
                'tutor:id,name,email,avatar,headline',
                'materials:id,class_session_id,course_id,title,file_name,file_type,file_size',
                'quiz:id,title,description,time_limit,passing_score',
                'quiz.questions:id,quiz_id',
                'attendances' => fn ($aq) => $aq->where('user_id', $user->id)->select(['id', 'class_session_id', 'user_id', 'status', 'joined_at']),
            ]);

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        $sessions = $query->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        $enriched = $this->enrichSessionsCollection($sessions, $user);

        return response()->json($enriched);
    }

    /**
     * Get today's class sessions for enrolled courses.
     * LIVE sessions appear first, followed by scheduled sessions.
     */
    public function today(Request $request): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();
        $now = Carbon::now(config('app.business_timezone'));
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $query = ClassSession::whereIn('course_id', function ($sq) use ($user) {
                $sq->select('course_id')->from('course_enrollments')->where('user_id', $user->id)->where('status', 'active');
            })
            ->where('scheduled_date', $today)
            ->whereIn('status', ['scheduled', 'live'])
            ->where('end_time', '>', $currentTime)
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url',
            ])
            ->with([
                'course:id,title,category,thumbnail,slug',
                'tutor:id,name,email,avatar,headline',
                'materials:id,class_session_id,course_id,title,file_name,file_type,file_size',
                'quiz:id,title,description,time_limit,passing_score',
                'quiz.questions:id,quiz_id',
                'attendances' => fn ($aq) => $aq->where('user_id', $user->id)->select(['id', 'class_session_id', 'user_id', 'status', 'joined_at']),
            ]);

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        $sessions = $query->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('start_time', 'asc')
            ->get();

        $enriched = $this->enrichSessionsCollection($sessions, $user);

        return response()->json($enriched);
    }

    /**
     * Get upcoming / live class sessions for enrolled courses.
     * LIVE sessions appear first, followed by upcoming sessions.
     */
    public function upcoming(Request $request): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();
        $now = Carbon::now(config('app.business_timezone'));
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $query = ClassSession::whereIn('course_id', function ($sq) use ($user) {
                $sq->select('course_id')->from('course_enrollments')->where('user_id', $user->id)->where('status', 'active');
            })
            ->whereIn('status', ['scheduled', 'live'])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('scheduled_date', '>', $today)
                    ->orWhere(function ($q2) use ($today, $currentTime) {
                        $q2->where('scheduled_date', '=', $today)
                            ->where('end_time', '>', $currentTime);
                    });
            })
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url',
            ])
            ->with([
                'course:id,title,category,thumbnail,slug',
                'tutor:id,name,email,avatar,headline',
                'materials:id,class_session_id,course_id,title,file_name,file_type,file_size',
                'quiz:id,title,description,time_limit,passing_score',
                'quiz.questions:id,quiz_id',
                'attendances' => fn ($aq) => $aq->where('user_id', $user->id)->select(['id', 'class_session_id', 'user_id', 'status', 'joined_at']),
            ]);

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        $sessions = $query->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC")
            ->orderBy('scheduled_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        $enriched = $this->enrichSessionsCollection($sessions, $user);

        return response()->json($enriched);
    }

    /**
     * Get previous / completed class sessions with rich materials, quiz, and attendance indicators.
     */
    public function previous(Request $request): JsonResponse
    {
        ClassSession::syncRealtimeStatuses();

        $user = $request->user();
        $now = Carbon::now(config('app.business_timezone'));
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $query = ClassSession::whereIn('course_id', function ($sq) use ($user) {
                $sq->select('course_id')->from('course_enrollments')->where('user_id', $user->id)->where('status', 'active');
            })
            ->where(function ($q) use ($today, $currentTime) {
                $q->whereIn('status', ['completed', 'expired'])
                    ->orWhere(function ($q2) use ($today) {
                        $q2->where('scheduled_date', '<', $today)
                            ->where('status', '!=', 'cancelled');
                    })
                    ->orWhere(function ($q3) use ($today, $currentTime) {
                        $q3->where('scheduled_date', '=', $today)
                            ->where('end_time', '<', $currentTime)
                            ->where('status', '!=', 'cancelled');
                    });
            })
            ->select([
                'id', 'course_id', 'tutor_id', 'quiz_id', 'title', 'description',
                'platform', 'meeting_url', 'meeting_id', 'meeting_password',
                'scheduled_date', 'start_time', 'end_time', 'status', 'expired_at', 'ended_at', 'recording_url',
            ])
            ->with([
                'course:id,title,category,thumbnail,slug',
                'tutor:id,name,email,avatar,headline',
                'materials:id,class_session_id,course_id,title,file_name,file_type,file_size',
                'quiz:id,title,description,time_limit,passing_score',
                'quiz.questions:id,quiz_id',
                'attendances' => fn ($aq) => $aq->where('user_id', $user->id)->select(['id', 'class_session_id', 'user_id', 'status', 'joined_at']),
            ]);

        if ($request->filled('course_id') && $request->course_id !== 'all') {
            $query->where('course_id', $request->course_id);
        }

        $sessions = $query->orderBy('scheduled_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        $enriched = $this->enrichSessionsCollection($sessions, $user);

        return response()->json($enriched);
    }

    /**
     * Get single class session details with strict enrollment check.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = ClassSession::with([
            'course:id,title,category,thumbnail,slug,description',
            'tutor:id,name,email,avatar,headline',
            'materials.uploader:id,name,email',
            'quiz.questions.options',
            'attendances' => fn ($aq) => $aq->where('user_id', $user->id),
        ])->findOrFail($id);

        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $session->course_id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        if (! $isEnrolled && ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. You are not enrolled in the course for this session.',
            ], 403);
        }

        $collection = new Collection([$session]);
        $enriched = $this->enrichSessionsCollection($collection, $user);

        return response()->json($enriched[0] ?? []);
    }

    /**
     * Join class session, record attendance, and return real meeting URL.
     */
    public function join(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $session = ClassSession::findOrFail($id);

        $isEnrolled = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $session->course_id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        if (! $isEnrolled && ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. You are not enrolled in this course.',
            ], 403);
        }

        if ($session->status === 'cancelled') {
            return response()->json([
                'message' => 'This class has been cancelled and cannot be joined.',
            ], 400);
        }

        if (empty($session->meeting_url)) {
            return response()->json([
                'message' => 'No meeting URL has been configured for this class session.',
            ], 400);
        }

        // Record attendance
        ClassSessionAttendance::updateOrCreate(
            [
                'class_session_id' => $session->id,
                'user_id' => $user->id,
            ],
            [
                'status' => 'present',
                'joined_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Attendance logged. Proceeding to live classroom.',
            'meeting_url' => $session->meeting_url,
            'platform' => $session->platform,
            'meeting_id' => $session->meeting_id,
            'meeting_password' => $session->meeting_password,
        ]);
    }

    /**
     * Batch enrich a collection of class sessions in 2 queries, eliminating N+1 queries.
     */
    private function enrichSessionsCollection(Collection $sessions, User $user): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $courseIds = $sessions->pluck('course_id')->unique()->filter()->values();
        $sessionsWithoutQuizCourseIds = $sessions->whereNull('quiz_id')->pluck('course_id')->unique()->filter()->values();

        // 1. Batch load fallback course quizzes for sessions that lack a direct quiz_id
        $courseQuizzes = collect();
        if ($sessionsWithoutQuizCourseIds->isNotEmpty()) {
            $courseQuizzes = Quiz::whereHas('lesson', fn ($l) => $l->whereIn('course_id', $sessionsWithoutQuizCourseIds))
                ->with('lesson:id,course_id', 'questions:id,quiz_id')
                ->get()
                ->keyBy(fn ($q) => $q->lesson?->course_id);
        }

        // 2. Collect all quiz IDs across direct session quizzes and fallback course quizzes
        $allQuizIds = $sessions->pluck('quiz_id')
            ->concat($courseQuizzes->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        // 3. Batch load attempts for the student across all identified quizzes in a single query
        $quizAttempts = collect();
        if ($allQuizIds->isNotEmpty()) {
            $quizAttempts = QuizAttempt::where('user_id', $user->id)
                ->whereIn('quiz_id', $allQuizIds)
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('quiz_id')
                ->map(fn ($attempts) => $attempts->first()); // Latest attempt
        }

        // 4. Batch load active batch per course with student preference in a single query
        $courseBatches = Batch::leftJoin('batch_students', function ($join) use ($user) {
                $join->on('batches.id', '=', 'batch_students.batch_id')
                    ->where('batch_students.user_id', '=', $user->id)
                    ->where('batch_students.status', '=', 'active');
            })
            ->whereIn('batches.course_id', $courseIds)
            ->select('batches.id', 'batches.code', 'batches.name', 'batches.course_id', 'batches.start_date', 'batch_students.id as is_student_assigned')
            ->orderBy('is_student_assigned', 'desc')
            ->orderBy('batches.start_date', 'desc')
            ->get()
            ->groupBy('course_id');

        return $sessions->map(function (ClassSession $session) use ($user, $courseQuizzes, $quizAttempts, $courseBatches) {
            // Batch code resolution purely in-memory
            $batch = $courseBatches->get($session->course_id)?->first();
            $batchCode = $batch?->code;
            if (! $batchCode) {
                $batchCode = $session->course
                    ? Batch::generateBatchCode($session->course, $session->scheduled_date ?? now(), false)
                    : 'RIT(TECH)BC'.now()->format('dmy');
            }

            // Attendance status
            $att = $session->attendances->first();
            $attendanceStatus = $att ? ucfirst($att->status) : 'Not Recorded';

            // Materials
            $materials = ($session->materials ?? collect([]))->map(fn ($m) => [
                'id' => $m->id,
                'class_session_id' => $m->class_session_id,
                'course_id' => $m->course_id,
                'title' => $m->title,
                'file_name' => $m->file_name,
                'file_type' => $m->file_type,
                'file_size' => $m->file_size,
                'download_url' => '/api/materials/'.$m->id.'/download',
            ])->values();
            $materialsCount = $materials->count();
            $materialsText = $materialsCount > 0 ? "{$materialsCount} shared" : 'None shared';

            // Quiz resolution
            $quiz = $session->quiz ?: ($courseQuizzes->get($session->course_id) ?? null);
            $quizInfo = null;
            $quizStatus = 'Not assigned';

            if ($quiz) {
                $questionCount = $quiz->questions ? $quiz->questions->count() : 0;
                $attempt = $quizAttempts->get($quiz->id);

                $attemptStatus = 'Not started';
                $score = null;
                $passed = null;

                if ($attempt) {
                    if ($attempt->completed_at) {
                        $attemptStatus = 'Completed';
                        $quizStatus = 'Completed';
                        $score = (float) $attempt->score;
                        $passed = (bool) $attempt->passed;
                    } else {
                        $attemptStatus = 'In Progress';
                        $quizStatus = 'Available';
                    }
                } else {
                    $quizStatus = 'Available';
                }

                $quizInfo = [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'description' => $quiz->description,
                    'time_limit' => $quiz->time_limit,
                    'passing_score' => $quiz->passing_score,
                    'questions_count' => $questionCount,
                    'status' => $quizStatus,
                    'attempt_status' => $attemptStatus,
                    'score' => $score,
                    'total_marks' => $attempt ? (float) $attempt->total_marks : null,
                    'passed' => $passed,
                ];
            }

            return [
                'id' => $session->id,
                'course_id' => $session->course_id,
                'course_title' => $session->course?->title ?? 'Course',
                'course' => $session->course,
                'batch_code' => $batchCode,
                'batch_number' => $batchCode,
                'batch' => $batch ? [
                    'id' => $batch->id,
                    'code' => $batch->code,
                    'name' => $batch->name,
                    'start_date' => $batch->start_date ? $batch->start_date->toDateString() : (string) $batch->start_date,
                ] : null,
                'title' => $session->title,
                'description' => $session->description,
                'platform' => $session->platform,
                'meeting_url' => $session->status === 'cancelled' ? null : $session->meeting_url,
                'meeting_id' => $session->meeting_id,
                'meeting_password' => $session->meeting_password,
                'scheduled_date' => $session->scheduled_date ? $session->scheduled_date->toDateString() : (string) $session->scheduled_date,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'status' => $session->calculateStatus(),
                'is_live' => ($session->calculateStatus() === 'live'),
                'is_scheduled' => ($session->calculateStatus() === 'scheduled'),
                'is_expired' => ($session->calculateStatus() === 'expired'),
                'tutor_id' => $session->tutor_id,
                'tutor_name' => $session->tutor?->name ?? 'Instructor',
                'tutor_avatar' => $session->tutor?->avatar,
                'tutor' => $session->tutor,
                'materials' => $materials,
                'materials_count' => $materialsCount,
                'materials_text' => $materialsText,
                'materials_shared_text' => $materialsText,
                'attendance_status' => $attendanceStatus,
                'quiz' => $quizInfo,
                'quiz_info' => $quizInfo,
                'quiz_status' => $quizStatus,
                'recording_url' => $session->recording_url,
            ];
        })->toArray();
    }
}
