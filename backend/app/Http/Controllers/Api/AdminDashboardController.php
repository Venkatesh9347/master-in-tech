<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Get aggregated Admin Operations Dashboard data.
     */
    public function index(Request $request)
    {
        $today = Carbon::today();

        // 1. TOP STATISTICS / KPIs
        $totalStudents = User::where('role', 'student')->count();
        $activeStudents = CourseEnrollment::where('status', 'active')->distinct('user_id')->count('user_id');
        $totalTutors = User::where('role', 'tutor')->count();
        $activeTutors = Course::whereNotNull('instructor_id')->distinct('instructor_id')->count('instructor_id');
        $totalCourses = Course::count();
        $publishedCourses = Course::where('is_published', true)->count();
        $newEnquiries = Enquiry::where('status', Enquiry::STATUS_NEW)->count();
        $todaysDemosCount = Enquiry::whereDate('demo_date', $today)->count();
        if ($todaysDemosCount === 0) {
            $todaysDemosCount = Enquiry::where('status', Enquiry::STATUS_DEMO_SCHEDULED)->count();
        }
        $pendingAdmissions = Enquiry::whereIn('status', [Enquiry::STATUS_ADMISSION_CONFIRMED, Enquiry::STATUS_INTERESTED])->count();
        $activeEnrollments = CourseEnrollment::where('status', 'active')->count();
        $totalAdmins = User::where('role', 'admin')->count();

        $statistics = [
            'total_students' => $totalStudents,
            'active_students' => $activeStudents,
            'total_tutors' => $totalTutors,
            'active_tutors' => $activeTutors,
            'total_admins' => $totalAdmins,
            'total_courses' => $totalCourses,
            'published_courses' => $publishedCourses,
            'new_enquiries' => $newEnquiries,
            'todays_demos' => $todaysDemosCount,
            'pending_admissions' => $pendingAdmissions,
            'active_enrollments' => $activeEnrollments,
            'certificates_issued' => Certificate::count(),
            'pending_assignments' => AssignmentSubmission::where('status', 'submitted')->count(),
        ];

        // 2. ADMISSIONS PIPELINE STAGES
        $admissionsPipeline = [
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

        // 3. RECENT ENQUIRIES (Latest 6)
        $recentEnquiries = Enquiry::with('course:id,title')
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get();

        // 4. TODAY'S & UPCOMING DEMOS
        $todaysDemos = Enquiry::whereNotNull('demo_date')
            ->orWhere('status', Enquiry::STATUS_DEMO_SCHEDULED)
            ->orderBy('demo_date', 'asc')
            ->limit(6)
            ->get();

        // 5. RECENT ADMISSIONS & RECENTLY ENROLLED LEADS
        $recentAdmissions = Enquiry::whereIn('status', [Enquiry::STATUS_ADMISSION_CONFIRMED, Enquiry::STATUS_ENROLLED])
            ->orderBy('updated_at', 'desc')
            ->limit(6)
            ->get();

        // 6. COURSES OVERVIEW
        $coursesOverview = Course::withCount(['enrollments', 'sections', 'lessons'])
            ->withAvg('reviews', 'rating')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get()
            ->map(function ($c) {
                return [
                    'id' => $c->id,
                    'title' => $c->title,
                    'category' => $c->category ?? 'Software Engineering',
                    'instructor' => $c->instructor,
                    'is_published' => (bool) $c->is_published,
                    'status' => $c->status ?? ($c->is_published ? 'published' : 'draft'),
                    'enrollments_count' => $c->enrollments_count,
                    'sections_count' => $c->sections_count,
                    'lessons_count' => $c->lessons_count,
                    'duration' => $c->duration,
                    'difficulty' => $c->difficulty,
                    'average_rating' => $c->reviews_avg_rating ? round((float) $c->reviews_avg_rating, 1) : 4.9,
                    'internal_price' => (float) $c->price, // Admin only
                ];
            });

        // 7. STUDENT ACTIVITY (Recent enrollments, completed lessons, certificates)
        $recentEnrollments = CourseEnrollment::with(['user:id,name,email', 'course:id,title'])
            ->orderBy('enrolled_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($enr) {
                return [
                    'type' => 'enrollment',
                    'student_name' => $enr->user?->name ?? 'Student',
                    'student_email' => $enr->user?->email,
                    'course_title' => $enr->course?->title ?? 'Course',
                    'timestamp' => $enr->enrolled_at ? $enr->enrolled_at->toISOString() : now()->toISOString(),
                ];
            });

        $recentCertificates = Certificate::with(['user:id,name', 'course:id,title'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($cert) {
                return [
                    'type' => 'certificate',
                    'student_name' => $cert->user?->name ?? 'Student',
                    'course_title' => $cert->course?->title ?? 'Course',
                    'certificate_code' => $cert->certificate_code,
                    'timestamp' => $cert->created_at ? $cert->created_at->toISOString() : now()->toISOString(),
                ];
            });

        // 8. TUTOR ACTIVITY (Tutors with course counts)
        $tutorActivity = User::where('role', 'tutor')
            ->withCount(['taughtCourses'])
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get();

        // 9. SYSTEM ACTIVITY STREAM
        $systemActivity = [];

        foreach (Enquiry::orderBy('created_at', 'desc')->limit(4)->get() as $enq) {
            $systemActivity[] = [
                'event' => 'New demo enquiry received',
                'description' => "{$enq->name} requested demo for {$enq->course_title}",
                'user' => $enq->name,
                'status' => $enq->status,
                'timestamp' => $enq->created_at ? $enq->created_at->toISOString() : now()->toISOString(),
                'icon' => '📬',
            ];
        }

        foreach (CourseEnrollment::with(['user', 'course'])->orderBy('enrolled_at', 'desc')->limit(4)->get() as $enr) {
            $systemActivity[] = [
                'event' => 'Student admitted & enrolled',
                'description' => ($enr->user?->name ?? 'Student') . " enrolled in " . ($enr->course?->title ?? 'Course'),
                'user' => $enr->user?->name ?? 'Student',
                'status' => 'enrolled',
                'timestamp' => $enr->enrolled_at ? $enr->enrolled_at->toISOString() : now()->toISOString(),
                'icon' => '🎓',
            ];
        }

        foreach (EnquiryNote::with('enquiry')->orderBy('created_at', 'desc')->limit(4)->get() as $note) {
            $systemActivity[] = [
                'event' => 'Admissions note logged',
                'description' => $note->note,
                'user' => $note->user_name,
                'status' => 'follow_up',
                'timestamp' => $note->created_at ? $note->created_at->toISOString() : now()->toISOString(),
                'icon' => '📝',
            ];
        }

        usort($systemActivity, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        $systemActivity = array_slice($systemActivity, 0, 10);

        return response()->json([
            'statistics' => $statistics,
            'admissions_pipeline' => $admissionsPipeline,
            'recent_enquiries' => $recentEnquiries,
            'todays_demos' => $todaysDemos,
            'recent_admissions' => $recentAdmissions,
            'courses_overview' => $coursesOverview,
            'student_activity' => [
                'recent_enrollments' => $recentEnrollments,
                'recent_certificates' => $recentCertificates,
            ],
            'tutor_activity' => $tutorActivity,
            'system_activity' => $systemActivity,
        ]);
    }
}
