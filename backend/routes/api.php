<?php

use App\Http\Controllers\Api\AdminAssignmentGradeController;
use App\Http\Controllers\Api\AdminBatchController;
use App\Http\Controllers\Api\AdminClassSessionController;
use App\Http\Controllers\Api\AdminCmsController;
use App\Http\Controllers\Api\AdminCorporatePartnerController;
use App\Http\Controllers\Api\AdminCrmController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AdminEnrollmentController;
use App\Http\Controllers\Api\AdminEventController;
use App\Http\Controllers\Api\AdminLiveClassroomController;
use App\Http\Controllers\Api\AdminMockInterviewController;
use App\Http\Controllers\Api\AdminPlacementController;
use App\Http\Controllers\Api\AdminTutorPermissionController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CallRecordingController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\ClassroomChatController;
use App\Http\Controllers\Api\ClassroomInteractionController;
use App\Http\Controllers\Api\ClassroomModerationController;
use App\Http\Controllers\Api\ClassSessionLiveKitController;
use App\Http\Controllers\Api\CompanyPortalController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseProgressController;
use App\Http\Controllers\Api\CourseReviewController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\EnquiryController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventRegistrationController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\LessonDiscussionController;
use App\Http\Controllers\Api\LessonNoteController;
use App\Http\Controllers\Api\LiveClassController;
use App\Http\Controllers\Api\LiveClassroomController;
use App\Http\Controllers\Api\PlacementPortalController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\StudentClassSessionController;
use App\Http\Controllers\Api\StudentMockInterviewController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\TutorClassSessionController;
use App\Http\Controllers\Api\TutorController;
use App\Http\Controllers\Api\TutorMaterialController;
use App\Http\Controllers\Api\TutorQuizController;
use App\Http\Controllers\Api\VideoPlaybackController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\LiveKitWebhookController;
use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Authentication & Health Routes
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-login');
Route::post('/auth/google', [AuthController::class, 'googleLogin'])->middleware('throttle:auth-otp-send');
Route::post('/auth/mobile/send-otp', [AuthController::class, 'mobileLogin'])->middleware('throttle:auth-otp-send');
Route::post('/auth/mobile/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth-otp-verify');
Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth-otp-verify');
Route::post('/auth/otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:auth-otp-resend');
Route::post('/enquiries', [EnquiryController::class, 'store'])->middleware('throttle:public-submission');

Route::get('/health', function (App\Services\Infrastructure\RedisHealthService $redis) {
    $databaseOk = false;

    try {
        DB::select('select 1');
        $databaseOk = true;
    } catch (\Throwable $e) {
        // Database unreachable: reported below. Never leak exception details.
    }

    return response()->json([
        'status' => 'ok',
        'service' => 'master-in-tech-api',
        'database' => [
            'status' => $databaseOk ? 'ok' : 'error',
            'driver' => config('database.default'),
        ],
        'redis' => $redis->status(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Payment Webhook (public, signature verified, throttled)
|--------------------------------------------------------------------------
| The endpoint is intentionally unauthenticated: the gateway cannot present an
| API token. Integrity is guaranteed by HMAC webhook-signature verification.
|*/
Route::post('/payments/razorpay/webhook', [PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:webhook');

/*
|--------------------------------------------------------------------------
| LiveKit Webhook (public, signature verified, throttled)
|--------------------------------------------------------------------------
| LiveKit signs webhook requests with a JWT (Bearer token) using the API
| secret. The endpoint is unauthenticated because LiveKit cannot present a
| Sanctum token; integrity is guaranteed by JWT signature verification. It
| updates class-session attendance in response to participant_joined,
| participant_left, room_started and room_finished events.
|*/
Route::post('/livekit/webhook', [LiveKitWebhookController::class, 'handle'])
    ->middleware('throttle:webhook');

/*
|--------------------------------------------------------------------------
| Public Dynamic CMS & Catalog Endpoints (Goal 4)
|--------------------------------------------------------------------------
| API-006: /api/public/courses and /api/public/courses/{slug} are DEPRECATED
| in favor of GET /api/courses (CourseController::index) and
| GET /api/courses/{id} (CourseController::show). The deprecated routes are
| kept operational for backward compatibility; the frontend uses /api/courses.
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    Route::get('/home', [PublicApiController::class, 'home']);
    Route::get('/categories', [PublicApiController::class, 'categories']);
    Route::get('/courses', [PublicApiController::class, 'courses']);
    Route::get('/courses/{slug}', [PublicApiController::class, 'course']);
    Route::get('/instructors', [PublicApiController::class, 'instructors']);
    Route::get('/learning-paths', [PublicApiController::class, 'learningPaths']);
    Route::get('/testimonials', [PublicApiController::class, 'testimonials']);
    Route::get('/faqs', [PublicApiController::class, 'faqs']);
    Route::get('/resources', [PublicApiController::class, 'resources']);
    Route::get('/settings', [PublicApiController::class, 'settings']);
    Route::get('/navigation', [PublicApiController::class, 'navigation']);
});

Route::post('/forgot-password', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
    ]);

    $status = Password::sendResetLink(
        $request->only('email')
    );

    if ($status === Password::RESET_LINK_SENT) {
        return response()->json([
            'message' => 'Password reset link sent successfully.',
        ]);
    }

    return response()->json([
        'message' => __($status),
    ], 422);
})->middleware('throttle:auth-forgot');

Route::post('/reset-password', function (Request $request) {
    $request->validate([
        'token' => ['required', 'string'],
        'email' => ['required', 'email'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
    ]);

    $status = Password::reset(
        $request->only('email', 'password', 'password_confirmation', 'token'),
        function ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => null,
            ])->save();
        }
    );

    if ($status === Password::PASSWORD_RESET) {
        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    return response()->json([
        'message' => __($status),
    ], 422);
})->middleware('throttle:auth-forgot');

/*
|--------------------------------------------------------------------------
| Public Course & Content Catalog Routes
|--------------------------------------------------------------------------
*/
Route::get('/courses', [CourseController::class, 'index']);
Route::get('/course-categories', [CourseController::class, 'categories']);
Route::get('/courses/{id}', [CourseController::class, 'show']);
Route::get('/courses/{course}/brochure', [CourseController::class, 'downloadBrochure']);
Route::get('/courses/{course}/sections', [SectionController::class, 'index']);
Route::get('/courses/{course}/reviews', [CourseReviewController::class, 'index']);
Route::get('/verify-certificate/{code}', [CertificateController::class, 'verify']);
Route::get('/certificates/{code}', [CertificateController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Public Event Routes
|--------------------------------------------------------------------------
*/
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/upcoming', [EventController::class, 'upcoming']);
Route::get('/events/past', [EventController::class, 'past']);
Route::get('/events/{id}', [EventController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Dedicated Placement Portal Routes (Public Opportunities Catalog)
|--------------------------------------------------------------------------
*/
Route::get('/placements/settings', [PlacementPortalController::class, 'settings']);
Route::get('/placements/opportunities', [PlacementPortalController::class, 'opportunities']);
Route::get('/placements/opportunities/{opportunity}', [PlacementPortalController::class, 'showOpportunity']);
Route::post('/corporate-partner/register', [CompanyPortalController::class, 'register'])->middleware('throttle:public-submission');

/*
|--------------------------------------------------------------------------
| Dedicated Company / IT Partner Portal Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'single.session', 'company'])->prefix('company')->group(function () {
    Route::get('/dashboard', [CompanyPortalController::class, 'dashboard']);
    Route::get('/profile', [CompanyPortalController::class, 'profile']);
    Route::put('/profile', [CompanyPortalController::class, 'updateProfile']);
    Route::get('/jobs', [CompanyPortalController::class, 'jobs']);
    Route::post('/jobs', [CompanyPortalController::class, 'storeJob']);
    Route::get('/jobs/{job}', [CompanyPortalController::class, 'showJob']);
    Route::put('/jobs/{job}', [CompanyPortalController::class, 'updateJob']);
    Route::delete('/jobs/{job}', [CompanyPortalController::class, 'destroyJob']);
    Route::get('/applications', [CompanyPortalController::class, 'applications']);
    Route::put('/applications/{application}/status', [CompanyPortalController::class, 'updateApplicationStatus']);
    Route::get('/interviews', [CompanyPortalController::class, 'interviews']);
    Route::post('/interviews', [CompanyPortalController::class, 'scheduleInterview']);
    Route::post('/interviews/{interview}/feedback', [CompanyPortalController::class, 'submitFeedback']);
});

/*
|--------------------------------------------------------------------------
| Authenticated User & Student Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth:sanctum', 'single.session'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Payments (at-most-once order creation via idempotency key)
    Route::post('/payments/order', [PaymentController::class, 'createOrder'])->middleware('throttle:payment-order');

    // Payments — authoritative confirm (server-verified before marking paid)
    Route::post('/payments/confirm', [PaymentController::class, 'confirm'])->middleware('throttle:payment-order');

    // AI Assistant Endpoints
    Route::prefix('ai')->middleware('throttle:ai-chat')->group(function () {
        Route::post('/chat', [AiChatController::class, 'chat']);
        Route::get('/conversations', [AiChatController::class, 'index']);
        Route::get('/conversations/{conversation}', [AiChatController::class, 'show']);
    });

    // Dedicated Placement Portal (Authenticated Student Operations)
    Route::get('/placements/student-status', [PlacementPortalController::class, 'studentDashboardStatus']);
    Route::get('/student/placement-dashboard/status', [PlacementPortalController::class, 'studentDashboardStatus']);
    Route::get('/student/placement-status', [PlacementPortalController::class, 'studentDashboardStatus']);
    Route::get('/placements/profile-prefill', [PlacementPortalController::class, 'profilePrefill']);
    Route::post('/placements/opportunities/{opportunity}/apply', [PlacementPortalController::class, 'apply']);
    Route::get('/placements/my-applications', [PlacementPortalController::class, 'myApplications']);

    // Mandatory Mock Interview Gateway (Student Operations)
    Route::prefix('student/mock-interviews')->group(function () {
        Route::get('/eligibility', [StudentMockInterviewController::class, 'eligibility']);
        Route::get('/slots', [StudentMockInterviewController::class, 'slots']);
        Route::post('/book', [StudentMockInterviewController::class, 'book']);
        Route::get('/my-interviews', [StudentMockInterviewController::class, 'myInterviews']);
        Route::get('/{interview}', [StudentMockInterviewController::class, 'show']);
        Route::post('/{interview}/cancel', [StudentMockInterviewController::class, 'cancel']);
        Route::post('/{interview}/reschedule', [StudentMockInterviewController::class, 'reschedule']);
    });
    // Alias student route for mock-interview (singular)
    Route::get('/student/mock-interview/eligibility', [StudentMockInterviewController::class, 'eligibility']);
    Route::get('/student/mock-interview/slots', [StudentMockInterviewController::class, 'slots']);
    Route::post('/student/mock-interview/book', [StudentMockInterviewController::class, 'book']);
    Route::get('/student/mock-interview/my-interviews', [StudentMockInterviewController::class, 'myInterviews']);

    // Event Registration (write-amplified; per-user capped)
    Route::post('/events/{eventId}/register', [EventRegistrationController::class, 'register'])->middleware('throttle:lms-write');
    Route::delete('/events/{eventId}/register', [EventRegistrationController::class, 'cancel'])->middleware('throttle:lms-write');
    Route::get('/my-events', [EventRegistrationController::class, 'myEvents']);
    Route::get('/my-registrations', [EventRegistrationController::class, 'myRegistrations']);
    Route::get('/events/{eventId}/registration', [EventRegistrationController::class, 'checkRegistration']);

    // LMS Student Features
    Route::post('/courses/{course}/enroll', [EnrollmentController::class, 'enroll']);
    Route::get('/courses/{course}/enrollment', [EnrollmentController::class, 'check']);
    Route::get('/courses/{course}/enquiry', [EnquiryController::class, 'checkCourseEnquiry']);
    Route::get('/my-courses', [EnrollmentController::class, 'myCourses']);

    // Student Profile
    Route::get('/student/profile', [StudentProfileController::class, 'show']);
    Route::put('/student/profile', [StudentProfileController::class, 'update']);
    Route::get('/courses/{course}/lms-progress', [CourseProgressController::class, 'show']);
    Route::get('/courses/{course}/sections/{section}/lessons/{lesson}', [LessonController::class, 'show']);
    Route::post('/courses/{course}/lessons/{lesson}/start', [LessonController::class, 'start'])->middleware('throttle:lms-write');
    Route::post('/courses/{course}/lessons/{lesson}/playback-progress', [LessonController::class, 'playbackProgress'])->middleware('throttle:lms-write');
    Route::post('/courses/{course}/lessons/{lesson}/progress', [LessonController::class, 'playbackProgress'])->middleware('throttle:lms-write');
    Route::post('/courses/{course}/lessons/{lesson}/playback-auth', [VideoPlaybackController::class, 'authorizeLessonPlayback'])->middleware('throttle:playback-auth');
    Route::post('/courses/{course}/lessons/{lesson}/complete', [LessonController::class, 'complete'])->middleware('throttle:lms-write');
    Route::post('/courses/{course}/sections/{section}/lessons/{lesson}/complete', [LessonController::class, 'complete'])->middleware('throttle:lms-write');

    // Quizzes & Assignments
    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::post('/quizzes/{quiz}/start', [QuizController::class, 'start'])->middleware('throttle:lms-write');
    Route::post('/quiz-attempts/{attempt}/submit', [QuizController::class, 'submit'])->middleware('throttle:lms-write');
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::post('/assignments/{assignment}/submit', [AssignmentController::class, 'submit'])->middleware('throttle:lms-write');

    // Personal Notes & Discussions
    Route::get('/courses/{course}/lessons/{lesson}/note', [LessonNoteController::class, 'show']);
    Route::post('/courses/{course}/lessons/{lesson}/note', [LessonNoteController::class, 'store']);
    Route::get('/courses/{course}/lessons/{lesson}/discussions', [LessonDiscussionController::class, 'index']);
    Route::post('/courses/{course}/lessons/{lesson}/discussions', [LessonDiscussionController::class, 'store']);
    Route::post('/discussions/{discussion}/reply', [LessonDiscussionController::class, 'reply']);

    // Reviews & Certificates
    Route::post('/courses/{course}/reviews', [CourseReviewController::class, 'store']);
    Route::post('/courses/{course}/certificate', [CertificateController::class, 'generate'])->middleware('throttle:certificates');
    Route::get('/student/certificates/{code}/download', [CertificateController::class, 'download'])->middleware('throttle:certificates');

    // Real-Time Live Classroom (Enrolled Students)
    Route::get('/my-live-classes', [LiveClassController::class, 'myLiveClasses']);
    Route::get('/courses/{course}/live-classes', [LiveClassController::class, 'index']);
    Route::get('/live-classes/{liveClass}', [LiveClassController::class, 'show']);
    Route::post('/live-classes/{liveClass}/join', [LiveClassController::class, 'join']);
    Route::post('/live-classes/{liveClass}/leave', [LiveClassController::class, 'leave']);
    Route::post('/live-classes/{liveClass}/raise-hand', [LiveClassController::class, 'raiseHand']);
    Route::post('/live-classes/{liveClass}/lower-hand', [LiveClassController::class, 'lowerHand']);
    Route::get('/live-classes/{liveClass}/messages', [LiveClassController::class, 'messages']);
    Route::post('/live-classes/{liveClass}/messages', [LiveClassController::class, 'postMessage']);
    Route::get('/live-classes/{liveClass}/state', [LiveClassController::class, 'state']);

    // Goal 6: Student Class Sessions Management
    Route::get('/student/class-sessions', [StudentClassSessionController::class, 'index']);
    Route::get('/student/class-sessions/today', [StudentClassSessionController::class, 'today']);
    Route::get('/student/class-sessions/upcoming', [StudentClassSessionController::class, 'upcoming']);
    Route::get('/student/class-sessions/previous', [StudentClassSessionController::class, 'previous']);
    Route::get('/student/class-sessions/{id}', [StudentClassSessionController::class, 'show']);
    Route::post('/student/class-sessions/{id}/join', [StudentClassSessionController::class, 'join']);

    Route::get('/courses/{course}/progress', function (int $course) {
        $user = auth()->user();

        $enrollment = \App\Models\CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $course)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'Course Access Required. You must have an active enrollment in this course to view progress.',
                'enrollment_required' => true,
            ], 403);
        }

        $progress = LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course)
            ->where('completed', true)
            ->pluck('lesson_id');

        $totalLessons = DB::table('lessons')
            ->where('course_id', $course)
            ->where('is_published', true)
            ->count();

        $percentage = $totalLessons > 0
            ? round(($progress->count() / $totalLessons) * 100, 2)
            : 0;

        return response()->json([
            'completed_lessons' => $progress,
            'progress_percentage' => $percentage,
            'completed_count' => $progress->count(),
            'total_lessons' => $totalLessons,
        ]);
    });

    // Goal 8: Protected Learning Material Downloads
    Route::get('/materials/{id}/download', [TutorMaterialController::class, 'download']);
    Route::get('/class-materials/{id}/download', [TutorMaterialController::class, 'download']);

    // Class Sessions LiveKit WebRTC Token & Status
    Route::post('/class-sessions/{id}/livekit-token', [ClassSessionLiveKitController::class, 'generateToken'])->middleware('throttle:livekit-token');
    Route::post('/class-sessions/{id}/livekit-status', [ClassSessionLiveKitController::class, 'updateStatus']);
    Route::post('/class-sessions/{id}/leave', [ClassSessionLiveKitController::class, 'leaveSession']);

    // Internal Live Classroom System (Phase 1, 2, 3)
    Route::get('/live-classroom/sessions', [LiveClassroomController::class, 'index']);
    Route::get('/live-classroom/sessions/{id}', [LiveClassroomController::class, 'show']);
    Route::post('/live-classroom/sessions/{id}/token', [LiveClassroomController::class, 'generateToken'])->middleware('throttle:livekit-token');
    Route::post('/live-classroom/sessions/{id}/leave', [LiveClassroomController::class, 'leave']);
    Route::post('/live-classroom/sessions/{id}/start', [LiveClassroomController::class, 'start']);
    Route::post('/live-classroom/sessions/{id}/end', [LiveClassroomController::class, 'end']);

    // Phase 2 & 3: Live Classroom Moderation, Interaction, Removal & Chat
    Route::get('/classrooms/{id}/state', [ClassroomModerationController::class, 'getState']);
    Route::post('/classrooms/{id}/transfer-host', [ClassroomModerationController::class, 'transferHost']);
    Route::post('/classrooms/{id}/moderation/mic', [ClassroomModerationController::class, 'toggleMic']);
    Route::post('/classrooms/{id}/moderation/camera', [ClassroomModerationController::class, 'toggleCamera']);
    Route::post('/classrooms/{id}/moderation/chat-toggle', [ClassroomModerationController::class, 'toggleChat']);
    Route::post('/classrooms/{id}/remove-participant', [ClassroomModerationController::class, 'removeParticipant']);
    Route::post('/classrooms/{id}/leave', [ClassroomModerationController::class, 'leaveClassroom']);
    Route::post('/classrooms/{id}/end', [ClassroomModerationController::class, 'endClassroom']);

    Route::post('/classrooms/{id}/raise-hand', [ClassroomInteractionController::class, 'raiseHand']);
    Route::post('/classrooms/{id}/lower-hand', [ClassroomInteractionController::class, 'lowerHand']);
    Route::post('/classrooms/{id}/requests/{requestId}/resolve', [ClassroomInteractionController::class, 'resolveRequest']);

    Route::get('/classrooms/{id}/messages', [ClassroomChatController::class, 'getMessages']);
    Route::post('/classrooms/{id}/messages', [ClassroomChatController::class, 'sendMessage']);
});

/*
|--------------------------------------------------------------------------
| Tutor & Instructor Routes (Role: 'tutor' or 'admin')
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'single.session', 'tutor'])->prefix('tutor')->group(function () {
    Route::get('stats', [TutorController::class, 'stats']);
    Route::get('courses', [TutorController::class, 'courses']);
    Route::get('courses/{course}', [TutorController::class, 'showCourse']);
    Route::post('courses', [TutorController::class, 'storeCourse']);
    Route::put('courses/{course}', [TutorController::class, 'updateCourse']);
    Route::delete('courses/{course}', [TutorController::class, 'destroyCourse']);
    Route::get('courses/{course}/analytics', [TutorController::class, 'courseAnalytics']);
    Route::get('courses/{course}/students', [TutorController::class, 'courseStudents']);
    Route::get('students', [TutorController::class, 'students']);
    Route::get('submissions', [TutorController::class, 'submissions']);
    Route::post('submissions/{id}/grade', [TutorController::class, 'gradeSubmission']);

    Route::get('profile', [TutorController::class, 'profile']);
    Route::put('profile', [TutorController::class, 'updateProfile']);

    Route::get('courses/{course}/curriculum', [SectionController::class, 'index']);
    Route::post('courses/{course}/lessons/{lesson}/quiz', [TutorController::class, 'saveQuiz']);
    Route::post('courses/{course}/lessons/{lesson}/assignment', [TutorController::class, 'saveAssignment']);

    // Tutor Live Classes & Host Console
    Route::post('courses/{course}/live-classes', [LiveClassController::class, 'store']);
    Route::put('live-classes/{liveClass}', [LiveClassController::class, 'update']);
    Route::delete('live-classes/{liveClass}', [LiveClassController::class, 'destroy']);
    Route::post('live-classes/{liveClass}/start', [LiveClassController::class, 'start']);
    Route::post('live-classes/{liveClass}/end', [LiveClassController::class, 'end']);
    Route::post('live-classes/{liveClass}/toggle-chat', [LiveClassController::class, 'toggleChat']);
    Route::post('live-classes/{liveClass}/participants/{userId}/allow-mic', [LiveClassController::class, 'allowMic']);
    Route::post('live-classes/{liveClass}/participants/{userId}/revoke-mic', [LiveClassController::class, 'revokeMic']);
    Route::post('live-classes/{liveClass}/participants/{userId}/lower-hand', [LiveClassController::class, 'tutorLowerHand']);
    Route::post('live-classes/{liveClass}/participants/{userId}/remove', [LiveClassController::class, 'removeParticipant']);
    Route::get('live-classes/{liveClass}/attendance', [LiveClassController::class, 'attendance']);

    // Goal 6: Tutor Class Sessions Management
    Route::get('class-sessions', [TutorClassSessionController::class, 'index']);
    Route::get('class-sessions/today', [TutorClassSessionController::class, 'today']);
    Route::get('class-sessions/upcoming', [TutorClassSessionController::class, 'upcoming']);
    Route::get('class-sessions/previous', [TutorClassSessionController::class, 'previous']);
    Route::get('class-sessions/{id}', [TutorClassSessionController::class, 'show']);
    Route::post('class-sessions/{id}/join', [TutorClassSessionController::class, 'join']);
    Route::post('class-sessions/{id}/materials', [TutorClassSessionController::class, 'storeMaterial']);

    // Goal 8: Tutor Materials Management
    Route::get('materials', [TutorMaterialController::class, 'index']);
    Route::post('materials', [TutorMaterialController::class, 'store']);
    Route::get('materials/{id}', [TutorMaterialController::class, 'show']);
    Route::put('materials/{id}', [TutorMaterialController::class, 'update']);
    Route::delete('materials/{id}', [TutorMaterialController::class, 'destroy']);

    // Goal 8: Tutor Quizzes Management
    Route::get('quizzes', [TutorQuizController::class, 'index']);
    Route::post('quizzes', [TutorQuizController::class, 'store']);
    Route::get('quizzes/{id}', [TutorQuizController::class, 'show']);
    Route::put('quizzes/{id}', [TutorQuizController::class, 'update']);
    Route::delete('quizzes/{id}', [TutorQuizController::class, 'destroy']);
    Route::post('quizzes/{id}/toggle-publish', [TutorQuizController::class, 'togglePublish']);
    Route::get('quizzes/{id}/results', [TutorQuizController::class, 'results']);
});

// Curriculum routes accessible to course instructors and admins
Route::middleware(['auth:sanctum', 'single.session', 'tutor'])->group(function () {
    Route::get('/courses/{course}/curriculum', [SectionController::class, 'index']);
    Route::post('/courses/{course}/reorder', [SectionController::class, 'reorder']);

    Route::post('/courses/{course}/sections', [SectionController::class, 'store']);
    Route::put('/courses/{course}/sections/{section}', [SectionController::class, 'update']);
    Route::post('/courses/{course}/sections/{section}/toggle-publish', [SectionController::class, 'togglePublish']);
    Route::delete('/courses/{course}/sections/{section}', [SectionController::class, 'destroy']);

    Route::post('/courses/{course}/sections/{section}/lessons', [LessonController::class, 'store']);
    Route::put('/courses/{course}/sections/{section}/lessons/{lesson}', [LessonController::class, 'update']);
    Route::post('/courses/{course}/sections/{section}/lessons/{lesson}/toggle-publish', [LessonController::class, 'togglePublish']);
    Route::post('/courses/{course}/sections/{section}/lessons/{lesson}/publish', [LessonController::class, 'togglePublish']);
    Route::post('/courses/{course}/sections/{section}/lessons/{lesson}/unpublish', [LessonController::class, 'togglePublish']);
    Route::delete('/courses/{course}/sections/{section}/lessons/{lesson}', [LessonController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Admin-Only Routes (Role: 'admin')
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'single.session', 'admin'])->group(function () {
    // Admin Operations Dashboard Aggregation
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);

    // Platform statistics & User management
    Route::get('/admin/stats', [AdminUserController::class, 'stats']);
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::get('/admin/users/{user}', [AdminUserController::class, 'show']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::put('/admin/users/{user}', [AdminUserController::class, 'update']);
    Route::put('/admin/users/{user}/role', [AdminUserController::class, 'updateRole']);
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy']);

    // Admin course creation & deletion
    Route::post('/courses', [CourseController::class, 'store']);
    Route::put('/courses/{course}', [CourseController::class, 'update']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);

    // Admin Events Management
    Route::prefix('admin/events')->group(function () {
        Route::get('', [AdminEventController::class, 'index']);
        Route::post('', [AdminEventController::class, 'store']);
        Route::get('stats', [AdminEventController::class, 'stats']);
        Route::get('{event}', [AdminEventController::class, 'show']);
        Route::put('{event}', [AdminEventController::class, 'update']);
        Route::delete('{event}', [AdminEventController::class, 'destroy']);
        Route::get('{event}/registrations', [AdminEventController::class, 'registrations']);
    });

    // Admin Assignment Grading
    Route::get('/admin/assignments/submissions', [AdminAssignmentGradeController::class, 'index']);
    Route::post('/admin/assignments/submissions/{id}/grade', [AdminAssignmentGradeController::class, 'grade']);
});

/*
|--------------------------------------------------------------------------
| Dedicated CRM Module & Admissions Pipeline (Roles: super_admin, admin, counsellor, telecaller, course_advisor)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'single.session', 'crm'])->group(function () {
    // Enquiries & Admissions Pipeline (shared by admin, super_admin, counsellor, telecaller, course_advisor)
    Route::get('/admin/enquiries/stats', [EnquiryController::class, 'stats']);
    Route::get('/admin/enquiries', [EnquiryController::class, 'index']);
    Route::get('/admin/enquiries/{enquiry}', [EnquiryController::class, 'show']);
    Route::put('/admin/enquiries/{enquiry}', [EnquiryController::class, 'update']);
    Route::post('/admin/enquiries/{enquiry}/notes', [EnquiryController::class, 'addNote']);
    Route::post('/admin/enquiries/{enquiry}/enroll', [EnquiryController::class, 'enroll']);

    Route::prefix('admin/crm')->group(function () {
        Route::get('/stats', [AdminCrmController::class, 'stats']);
        Route::get('/counsellors', [AdminCrmController::class, 'counsellors']);
        Route::get('/follow-ups', [AdminCrmController::class, 'followUps']);
        Route::put('/follow-ups/{followUp}', [AdminCrmController::class, 'updateFollowUp']);
        Route::get('/leads', [AdminCrmController::class, 'index']);
        Route::get('/batch-options', [AdminBatchController::class, 'crmBatchOptions']);
        Route::post('/leads', [AdminCrmController::class, 'store']);
        Route::get('/leads/{lead}', [AdminCrmController::class, 'show']);
        Route::put('/leads/{lead}', [AdminCrmController::class, 'update']);
        Route::delete('/leads/{lead}', [AdminCrmController::class, 'destroy']);
        Route::post('/leads/{lead}/activities', [AdminCrmController::class, 'activities']);
        Route::post('/leads/{lead}/follow-ups', [AdminCrmController::class, 'storeFollowUp']);
        Route::post('/leads/{lead}/convert', [AdminCrmController::class, 'convert']);

        // Provider-independent call recordings (private storage, authorized
        // playback only — never public URLs).
        Route::get('/call-recordings', [CallRecordingController::class, 'index']);
        Route::post('/call-recordings', [CallRecordingController::class, 'store']);
        Route::get('/call-recordings/{recording}', [CallRecordingController::class, 'show']);
        Route::put('/call-recordings/{recording}', [CallRecordingController::class, 'update']);
        Route::post('/call-recordings/{recording}/upload', [CallRecordingController::class, 'upload'])
            ->middleware('throttle:crm-recordings');
        Route::get('/call-recordings/{recording}/download', [CallRecordingController::class, 'download'])
            ->middleware('throttle:crm-recordings');
        Route::delete('/call-recordings/{recording}', [CallRecordingController::class, 'destroy']);
    });
});

Route::middleware(['auth:sanctum', 'single.session', 'admin'])->group(function () {

    // Admin Placement Management Desk & Candidate Applications
    Route::prefix('admin/placements')->group(function () {
        Route::get('/stats', [AdminPlacementController::class, 'stats']);
        Route::get('/settings', [AdminPlacementController::class, 'getSettings']);
        Route::put('/settings', [AdminPlacementController::class, 'updateSettings']);
        Route::get('/opportunities', [AdminPlacementController::class, 'opportunities']);
        Route::post('/opportunities', [AdminPlacementController::class, 'storeOpportunity']);
        Route::get('/opportunities/{opportunity}', [AdminPlacementController::class, 'showOpportunity']);
        Route::put('/opportunities/{opportunity}', [AdminPlacementController::class, 'updateOpportunity']);
        Route::delete('/opportunities/{opportunity}', [AdminPlacementController::class, 'destroyOpportunity']);
        Route::get('/applications', [AdminPlacementController::class, 'applications']);
        Route::put('/applications/{application}/status', [AdminPlacementController::class, 'updateApplicationStatus']);
        Route::get('/dashboard-control', [AdminMockInterviewController::class, 'eligibilityList']);
        Route::post('/dashboard-control/status', [AdminMockInterviewController::class, 'updateDashboardStatus']);

        // Corporate Partners Desk
        Route::prefix('partners')->group(function () {
            Route::get('/', [AdminCorporatePartnerController::class, 'partners']);
            Route::get('/{company}', [AdminCorporatePartnerController::class, 'showPartner']);
            Route::post('/{company}/approve', [AdminCorporatePartnerController::class, 'approvePartner']);
            Route::post('/{company}/reject', [AdminCorporatePartnerController::class, 'rejectPartner']);
            Route::post('/{company}/suspend', [AdminCorporatePartnerController::class, 'suspendPartner']);
            Route::post('/{company}/reactivate', [AdminCorporatePartnerController::class, 'reactivatePartner']);
        });

        // Company Job Approvals
        Route::prefix('jobs')->group(function () {
            Route::get('/pending', [AdminCorporatePartnerController::class, 'pendingJobs']);
            Route::post('/{job}/approve', [AdminCorporatePartnerController::class, 'approveJob']);
            Route::post('/{job}/reject', [AdminCorporatePartnerController::class, 'rejectJob']);
        });

        // Mandatory Mock Interviews & Professional Interviewers Desk
        Route::prefix('mock-interviews')->group(function () {
            Route::get('/stats', [AdminMockInterviewController::class, 'stats']);
            Route::get('/eligibility', [AdminMockInterviewController::class, 'eligibilityList']);
            Route::post('/eligibility-override', [AdminMockInterviewController::class, 'overrideEligibility']);
            Route::post('/dashboard-control/status', [AdminMockInterviewController::class, 'updateDashboardStatus']);
            Route::get('/interviewers', [AdminMockInterviewController::class, 'interviewers']);
            Route::post('/interviewers', [AdminMockInterviewController::class, 'storeInterviewer']);
            Route::get('/interviewers/{interviewer}', [AdminMockInterviewController::class, 'showInterviewer']);
            Route::put('/interviewers/{interviewer}', [AdminMockInterviewController::class, 'updateInterviewer']);
            Route::delete('/interviewers/{interviewer}', [AdminMockInterviewController::class, 'destroyInterviewer']);
            Route::get('/slots', [AdminMockInterviewController::class, 'slots']);
            Route::post('/slots', [AdminMockInterviewController::class, 'storeSlot']);
            Route::put('/slots/{slot}', [AdminMockInterviewController::class, 'updateSlot']);
            Route::delete('/slots/{slot}', [AdminMockInterviewController::class, 'destroySlot']);
            Route::get('/bookings', [AdminMockInterviewController::class, 'bookings']);
            Route::get('/bookings/{interview}', [AdminMockInterviewController::class, 'showBooking']);
            Route::put('/bookings/{interview}/status', [AdminMockInterviewController::class, 'updateBookingStatus']);
            Route::post('/bookings/{interview}/reassign', [AdminMockInterviewController::class, 'reassignInterviewer']);
            Route::post('/bookings/{interview}/reschedule', [AdminMockInterviewController::class, 'rescheduleBooking']);
            Route::post('/bookings/{interview}/cancel', [AdminMockInterviewController::class, 'cancelBooking']);
            Route::post('/bookings/{interview}/evaluate', [AdminMockInterviewController::class, 'evaluateBooking']);
            Route::get('/evaluations', [AdminMockInterviewController::class, 'evaluations']);
        });
    });

    // Standalone /admin/mock-interviews alias
    Route::prefix('admin/mock-interviews')->group(function () {
        Route::get('/stats', [AdminMockInterviewController::class, 'stats']);
        Route::get('/eligibility', [AdminMockInterviewController::class, 'eligibilityList']);
        Route::post('/eligibility-override', [AdminMockInterviewController::class, 'overrideEligibility']);
        Route::post('/dashboard-control/status', [AdminMockInterviewController::class, 'updateDashboardStatus']);
        Route::get('/interviewers', [AdminMockInterviewController::class, 'interviewers']);
        Route::post('/interviewers', [AdminMockInterviewController::class, 'storeInterviewer']);
        Route::get('/interviewers/{interviewer}', [AdminMockInterviewController::class, 'showInterviewer']);
        Route::put('/interviewers/{interviewer}', [AdminMockInterviewController::class, 'updateInterviewer']);
        Route::delete('/interviewers/{interviewer}', [AdminMockInterviewController::class, 'destroyInterviewer']);
        Route::get('/slots', [AdminMockInterviewController::class, 'slots']);
        Route::post('/slots', [AdminMockInterviewController::class, 'storeSlot']);
        Route::put('/slots/{slot}', [AdminMockInterviewController::class, 'updateSlot']);
        Route::delete('/slots/{slot}', [AdminMockInterviewController::class, 'destroySlot']);
        Route::get('/bookings', [AdminMockInterviewController::class, 'bookings']);
        Route::get('/bookings/{interview}', [AdminMockInterviewController::class, 'showBooking']);
        Route::put('/bookings/{interview}/status', [AdminMockInterviewController::class, 'updateBookingStatus']);
        Route::post('/bookings/{interview}/reassign', [AdminMockInterviewController::class, 'reassignInterviewer']);
        Route::post('/bookings/{interview}/reschedule', [AdminMockInterviewController::class, 'rescheduleBooking']);
        Route::post('/bookings/{interview}/cancel', [AdminMockInterviewController::class, 'cancelBooking']);
        Route::post('/bookings/{interview}/evaluate', [AdminMockInterviewController::class, 'evaluateBooking']);
        Route::get('/evaluations', [AdminMockInterviewController::class, 'evaluations']);
    });

    // Singular /admin/placement/settings alias
    Route::get('/admin/placement/settings', [AdminPlacementController::class, 'getSettings']);
    Route::put('/admin/placement/settings', [AdminPlacementController::class, 'updateSettings']);

    // Admin Student Enrollments & Course Assignment Management
    Route::get('/admin/enrollments/stats', [AdminEnrollmentController::class, 'stats']);
    Route::get('/admin/enrollments', [AdminEnrollmentController::class, 'index']);
    Route::get('/admin/students/{user}/enrollments', [AdminEnrollmentController::class, 'studentEnrollments']);
    Route::post('/admin/enrollments', [AdminEnrollmentController::class, 'store']);
    Route::put('/admin/enrollments/{enrollment}', [AdminEnrollmentController::class, 'update']);
    Route::delete('/admin/enrollments/{enrollment}', [AdminEnrollmentController::class, 'destroy']);

    // Admin Batch Management & Cohort Operations
    Route::get('/admin/batches/stats', [AdminBatchController::class, 'stats']);
    Route::get('/admin/batches/history', [AdminBatchController::class, 'history']);
    Route::get('/admin/batches', [AdminBatchController::class, 'index']);
    Route::post('/admin/batches', [AdminBatchController::class, 'store']);
    Route::get('/admin/batches/{batch}', [AdminBatchController::class, 'show']);
    Route::put('/admin/batches/{batch}', [AdminBatchController::class, 'update']);
    Route::delete('/admin/batches/{batch}', [AdminBatchController::class, 'destroy']);
    Route::post('/admin/batches/{batch}/students', [AdminBatchController::class, 'addStudent']);
    Route::post('/admin/batches/{batch}/students/{user}/transfer', [AdminBatchController::class, 'transferStudent']);
    Route::post('/admin/batches/{batch}/students/{user}/discontinue', [AdminBatchController::class, 'discontinueStudent']);
    Route::post('/admin/batches/{batch}/students/{user}/rejoin', [AdminBatchController::class, 'rejoinStudent']);
    Route::delete('/admin/batches/{batch}/students/{user}', [AdminBatchController::class, 'removeStudent']);

    // Admin Student Learning Progress & Audit Logs
    Route::get('/admin/courses/{course}/students', [TutorController::class, 'courseStudents']);
    Route::get('/admin/students/{user}/progress', [AdminUserController::class, 'studentProgress']);
    Route::get('/admin/activity-logs', [AdminUserController::class, 'activityLogs']);

    // Admin CMS — Course Categories
    Route::get('/admin/categories', [AdminCmsController::class, 'categories']);
    Route::post('/admin/categories', [AdminCmsController::class, 'storeCategory']);
    Route::put('/admin/categories/{category}', [AdminCmsController::class, 'updateCategory']);
    Route::delete('/admin/categories/{category}', [AdminCmsController::class, 'destroyCategory']);
    Route::post('/admin/categories/reorder', [AdminCmsController::class, 'reorderCategories']);

    // Admin CMS — Home Page Sections
    Route::get('/admin/home-sections', [AdminCmsController::class, 'homeSections']);
    Route::put('/admin/home-sections/{section}', [AdminCmsController::class, 'updateHomeSection']);
    Route::post('/admin/home-sections/{section}/toggle', [AdminCmsController::class, 'toggleHomeSection']);

    // Admin CMS — Instructors & Faculty
    Route::get('/admin/instructors', [AdminCmsController::class, 'instructors']);
    Route::post('/admin/instructors', [AdminCmsController::class, 'storeInstructor']);
    Route::put('/admin/instructors/{instructor}', [AdminCmsController::class, 'updateInstructor']);
    Route::delete('/admin/instructors/{instructor}', [AdminCmsController::class, 'destroyInstructor']);

    // Admin CMS — Learning Paths
    Route::get('/admin/learning-paths', [AdminCmsController::class, 'learningPaths']);
    Route::post('/admin/learning-paths', [AdminCmsController::class, 'storeLearningPath']);
    Route::put('/admin/learning-paths/{learningPath}', [AdminCmsController::class, 'updateLearningPath']);
    Route::delete('/admin/learning-paths/{learningPath}', [AdminCmsController::class, 'destroyLearningPath']);

    // Admin CMS — Testimonials
    Route::get('/admin/testimonials', [AdminCmsController::class, 'testimonials']);
    Route::post('/admin/testimonials', [AdminCmsController::class, 'storeTestimonial']);
    Route::put('/admin/testimonials/{testimonial}', [AdminCmsController::class, 'updateTestimonial']);
    Route::delete('/admin/testimonials/{testimonial}', [AdminCmsController::class, 'destroyTestimonial']);

    // Admin CMS — FAQs
    Route::get('/admin/faqs', [AdminCmsController::class, 'faqs']);
    Route::post('/admin/faqs', [AdminCmsController::class, 'storeFaq']);
    Route::put('/admin/faqs/{faq}', [AdminCmsController::class, 'updateFaq']);
    Route::delete('/admin/faqs/{faq}', [AdminCmsController::class, 'destroyFaq']);

    // Admin CMS — Resources
    Route::get('/admin/resources', [AdminCmsController::class, 'resources']);
    Route::post('/admin/resources', [AdminCmsController::class, 'storeResource']);
    Route::put('/admin/resources/{resource}', [AdminCmsController::class, 'updateResource']);
    Route::delete('/admin/resources/{resource}', [AdminCmsController::class, 'destroyResource']);

    // Admin CMS — Website Settings
    Route::get('/admin/settings', [AdminCmsController::class, 'settings']);
    Route::put('/admin/settings', [AdminCmsController::class, 'updateSettings']);

    // Admin CMS — Navigation Items
    Route::get('/admin/navigation', [AdminCmsController::class, 'navigation']);
    Route::post('/admin/navigation', [AdminCmsController::class, 'storeNavigation']);
    Route::put('/admin/navigation/{navigation}', [AdminCmsController::class, 'updateNavigation']);
    Route::delete('/admin/navigation/{navigation}', [AdminCmsController::class, 'destroyNavigation']);

    // Admin CMS — Media Library
    Route::get('/admin/media', [AdminCmsController::class, 'media']);
    Route::post('/admin/media', [AdminCmsController::class, 'uploadMedia']);
    Route::post('/admin/media/upload', [AdminCmsController::class, 'uploadMedia']);
    Route::put('/admin/media/{media}', [AdminCmsController::class, 'updateMedia']);
    Route::post('/admin/media/{media}/replace', [AdminCmsController::class, 'replaceMedia']);
    Route::delete('/admin/media/{media}', [AdminCmsController::class, 'destroyMedia']);

    // Admin CMS — Audit Logs
    Route::get('/admin/audit-logs', [AdminCmsController::class, 'auditLogs']);

    // Goal 6: Admin Class Sessions Management (C-Panel)
    Route::get('/admin/class-sessions', [AdminClassSessionController::class, 'index']);
    Route::get('/admin/class-history', [AdminClassSessionController::class, 'history']);
    Route::get('/admin/class-history/{id}', [AdminClassSessionController::class, 'historyShow']);
    Route::post('/admin/class-sessions', [AdminClassSessionController::class, 'store']);
    Route::get('/admin/class-sessions/{id}', [AdminClassSessionController::class, 'show']);
    Route::put('/admin/class-sessions/{id}', [AdminClassSessionController::class, 'update']);
    Route::delete('/admin/class-sessions/{id}', [AdminClassSessionController::class, 'destroy']);
    Route::post('/admin/class-sessions/{id}/cancel', [AdminClassSessionController::class, 'cancel']);
    Route::post('/admin/class-sessions/{id}/materials', [AdminClassSessionController::class, 'storeMaterial']);
    Route::delete('/admin/class-sessions/{id}/materials/{materialId}', [AdminClassSessionController::class, 'destroyMaterial']);

    // Goal 8: Admin Faculty & Tutor Permissions Management
    Route::get('/admin/tutors', [AdminTutorPermissionController::class, 'index']);
    Route::get('/admin/tutors/{id}/permissions', [AdminTutorPermissionController::class, 'getPermissions']);
    Route::put('/admin/tutors/{id}/permissions', [AdminTutorPermissionController::class, 'updatePermissions']);

    // Admin Internal Live Classroom Management (Phase 1)
    Route::get('/admin/live-classroom/sessions', [AdminLiveClassroomController::class, 'index']);
    Route::post('/admin/live-classroom/sessions', [AdminLiveClassroomController::class, 'store']);
    Route::get('/admin/live-classroom/sessions/{id}', [AdminLiveClassroomController::class, 'show']);
    Route::put('/admin/live-classroom/sessions/{id}', [AdminLiveClassroomController::class, 'update']);
    Route::delete('/admin/live-classroom/sessions/{id}', [AdminLiveClassroomController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Encrypted Adaptive Video Streaming & Key Distribution (Token Authorized)
|
| Throttled per IP: legitimate HLS playback needs ~10-15 req/min; the cap
| only bites scrapers hammering manifests/segments.
|--------------------------------------------------------------------------
*/
Route::prefix('video-stream/{assetId}')->middleware('throttle:video-stream')->group(function () {
    Route::get('/master.m3u8', [VideoPlaybackController::class, 'getMasterPlaylist']);
    Route::get('/key', [VideoPlaybackController::class, 'getKey']);
    Route::get('/segments/{segment}', [VideoPlaybackController::class, 'getSegment']);
    Route::get('/{quality}.m3u8', [VideoPlaybackController::class, 'getVariantPlaylist']);
});
