import { lazy, Suspense } from "react";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";

// Eager Route Guards & Context Providers
import AdminRoute from "./components/AdminRoute";
import CounsellorRoute from "./components/CounsellorRoute";
import TutorRoute from "./components/TutorRoute";
import StudentRoute from "./components/StudentRoute";
import CompanyRoute from "./components/CompanyRoute";
import TutorLayout from "./components/tutor/TutorLayout";
import AdminLayout from "./components/admin/AdminLayout";
import CompanyLayout from "./components/company/CompanyLayout";
import { AuthProvider } from "./context/AuthContext";

// Lazy-Loaded Public Pages
const Home = lazy(() => import("./pages/Home"));
const Login = lazy(() => import("./pages/Login"));
const Register = lazy(() => import("./pages/Register"));
const ForgotPassword = lazy(() => import("./pages/ForgotPassword"));
const ResetPassword = lazy(() => import("./pages/ResetPassword"));
const Courses = lazy(() => import("./pages/Courses"));
const CourseCatalogDetails = lazy(() => import("./pages/CourseCatalogDetails"));
const Events = lazy(() => import("./pages/Events"));
const EventDetails = lazy(() => import("./pages/EventDetails"));
const CertificateVerify = lazy(() => import("./pages/CertificateVerify"));
const CertificateView = lazy(() => import("./pages/CertificateView"));
const About = lazy(() => import("./pages/About"));
const Contact = lazy(() => import("./pages/Contact"));
const Instructors = lazy(() => import("./pages/Instructors"));
const Resources = lazy(() => import("./pages/Resources"));
const Placements = lazy(() => import("./pages/Placements"));
const CorporatePartner = lazy(() => import("./pages/CorporatePartner"));
const FAQ = lazy(() => import("./pages/FAQ"));
const AiAssistant = lazy(() => import("./pages/AiAssistant"));
const NotFound = lazy(() => import("./pages/NotFound"));

// Lazy-Loaded Corporate Partner Portal Pages
const CompanyDashboard = lazy(() => import("./pages/company/CompanyDashboard"));
const CompanyJobs = lazy(() => import("./pages/company/CompanyJobs"));
const CompanyApplications = lazy(() => import("./pages/company/CompanyApplications"));
const CompanyInterviews = lazy(() => import("./pages/company/CompanyInterviews"));
const CompanyProfile = lazy(() => import("./pages/company/CompanyProfile"));

// Lazy-Loaded Student Pages
const StudentDashboard = lazy(() => import("./pages/StudentDashboard"));
const StudentClassDetails = lazy(() => import("./pages/StudentClassDetails"));
const CourseDetails = lazy(() => import("./pages/CourseDetails"));
const StudentLessons = lazy(() => import("./pages/StudentLessons"));
const StudentEvents = lazy(() => import("./pages/StudentEvents"));
const StudentProfile = lazy(() => import("./pages/StudentProfile"));
const StudentMockInterview = lazy(() => import("./pages/StudentMockInterview"));
const StudentCheckout = lazy(() => import("./pages/student/Checkout"));
const LiveClassroom = lazy(() => import("./pages/LiveClassroom"));
const InternalClassroom = lazy(() => import("./pages/InternalClassroom"));

// Lazy-Loaded Tutor Pages
const TutorDashboard = lazy(() => import("./pages/tutor/TutorDashboard"));
const TutorCourses = lazy(() => import("./pages/tutor/TutorCourses"));
const TutorCurriculum = lazy(() => import("./pages/tutor/TutorCurriculum"));
const TutorCourseAnalytics = lazy(() => import("./pages/tutor/TutorCourseAnalytics"));
const TutorMaterials = lazy(() => import("./pages/tutor/TutorMaterials"));
const TutorQuizzes = lazy(() => import("./pages/tutor/TutorQuizzes"));
const TutorSubmissions = lazy(() => import("./pages/tutor/TutorSubmissions"));
const TutorStudents = lazy(() => import("./pages/tutor/TutorStudents"));
const TutorProfile = lazy(() => import("./pages/tutor/TutorProfile"));
const TutorLiveClasses = lazy(() => import("./pages/tutor/TutorLiveClasses"));

// Lazy-Loaded Admin Pages
const Dashboard = lazy(() => import("./pages/Dashboard"));
const AdminClassSessions = lazy(() => import("./pages/admin/AdminClassSessions"));
const AdminClassHistory = lazy(() => import("./pages/admin/AdminClassHistory"));
const AdminTutorPermissions = lazy(() => import("./pages/admin/AdminTutorPermissions"));
const AdminEnquiries = lazy(() => import("./pages/admin/AdminEnquiries"));
const AdminCrm = lazy(() => import("./pages/admin/AdminCrm"));
const AdminPlacements = lazy(() => import("./pages/admin/AdminPlacements"));
const AdminUsers = lazy(() => import("./pages/admin/AdminUsers"));
const AdminEnrollments = lazy(() => import("./pages/admin/AdminEnrollments"));
const AdminBatches = lazy(() => import("./pages/admin/AdminBatches"));
const AdminCategories = lazy(() => import("./pages/admin/AdminCategories"));
const AdminHomeCMS = lazy(() => import("./pages/admin/AdminHomeCMS"));
const AdminInstructors = lazy(() => import("./pages/admin/AdminInstructors"));
const AdminLearningPaths = lazy(() => import("./pages/admin/AdminLearningPaths"));
const AdminTestimonials = lazy(() => import("./pages/admin/AdminTestimonials"));
const AdminFaqs = lazy(() => import("./pages/admin/AdminFaqs"));
const AdminResources = lazy(() => import("./pages/admin/AdminResources"));
const AdminSettings = lazy(() => import("./pages/admin/AdminSettings"));
const AdminNavigation = lazy(() => import("./pages/admin/AdminNavigation"));
const AdminMedia = lazy(() => import("./pages/admin/AdminMedia"));
const AdminAuditLogs = lazy(() => import("./pages/admin/AdminAuditLogs"));
const AdminEvents = lazy(() => import("./pages/AdminEvents"));
const AdminEventForm = lazy(() => import("./pages/AdminEventForm"));
const AdminEventRegistrations = lazy(() => import("./pages/AdminEventRegistrations"));
const AdminCurriculum = lazy(() => import("./pages/AdminCurriculum"));
const AdminSubmissions = lazy(() => import("./pages/AdminSubmissions"));

// Lightweight Suspense Fallback Loader
function PageLoadingFallback() {
  return (
    <div className="min-h-screen bg-slate-950 flex flex-col items-center justify-center text-slate-400 gap-4">
      <div className="relative flex items-center justify-center">
        <div className="w-12 h-12 rounded-full border-2 border-blue-500/20 border-t-blue-500 animate-spin" />
        <span className="absolute text-sm font-bold text-blue-400">⚡</span>
      </div>
      <p className="text-xs font-semibold tracking-wider text-slate-400 uppercase">
        Loading MasterInTech...
      </p>
    </div>
  );
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Suspense fallback={<PageLoadingFallback />}>
          <Routes>
            {/* Public Routes */}
            <Route path="/" element={<Home />} />
            <Route path="/login" element={<Login />} />
            <Route path="/register" element={<Register />} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/reset-password" element={<ResetPassword />} />
            <Route path="/reset-password/:token" element={<ResetPassword />} />

            {/* Public Catalog Routes */}
            <Route path="/courses" element={<Courses />} />
            <Route path="/courses/:id" element={<CourseCatalogDetails />} />

            {/* Public Events Routes */}
            <Route path="/events" element={<Events />} />
            <Route path="/events/:id" element={<EventDetails />} />

            {/* Public Certificate Verification */}
            <Route path="/verify-certificate" element={<CertificateVerify />} />
            <Route path="/verify-certificate/:code" element={<CertificateVerify />} />

            {/* Informational Pages */}
            <Route path="/about" element={<About />} />
            <Route path="/contact" element={<Contact />} />
            <Route path="/instructors" element={<Instructors />} />
            <Route path="/resources" element={<Resources />} />
            <Route path="/placements" element={<Placements />} />
            <Route path="/corporate-partner" element={<CorporatePartner />} />
            <Route path="/faq" element={<FAQ />} />

            {/* Dedicated Corporate Partner Portal Protected Routes */}
            <Route element={<CompanyRoute />}>
              <Route path="/company" element={<CompanyLayout />}>
                <Route index element={<CompanyDashboard />} />
                <Route path="jobs" element={<CompanyJobs />} />
                <Route path="applications" element={<CompanyApplications />} />
                <Route path="interviews" element={<CompanyInterviews />} />
                <Route path="profile" element={<CompanyProfile />} />
              </Route>
            </Route>

            {/* Student Protected Routes */}
            <Route element={<StudentRoute />}>
              <Route path="/student" element={<StudentDashboard />} />
              <Route path="/student/mock-interview" element={<StudentMockInterview />} />
              <Route path="/student/mock-interviews" element={<StudentMockInterview />} />
              <Route path="/student/class-sessions/:id" element={<StudentClassDetails />} />
              <Route path="/student/courses/:courseId" element={<CourseDetails />} />
              <Route path="/student/courses/:courseId/lessons" element={<StudentLessons />} />
              <Route path="/student/courses/:courseId/live/:liveClassId" element={<LiveClassroom />} />
              <Route path="/student/classroom/:sessionId" element={<InternalClassroom />} />
              <Route path="/student/events" element={<StudentEvents />} />
              <Route path="/student/certificates/:code" element={<CertificateView />} />
              <Route path="/student/checkout/:courseId" element={<StudentCheckout />} />
              <Route path="/student/profile" element={<StudentProfile />} />
              <Route path="/ai-assistant" element={<AiAssistant />} />
            </Route>

            {/* Tutor / Instructor Protected Routes (under /tutor) */}
            <Route element={<TutorRoute />}>
              <Route path="/tutor" element={<TutorLayout />}>
                <Route index element={<TutorDashboard />} />
                <Route path="courses" element={<TutorCourses />} />
                <Route path="courses/create" element={<Navigate to="/tutor/courses" replace />} />
                <Route path="courses/new" element={<Navigate to="/tutor/courses" replace />} />
                <Route path="courses/:id/edit" element={<Navigate to="/tutor/courses" replace />} />
                <Route path="courses/:id/curriculum" element={<TutorCurriculum />} />
                <Route path="courses/:courseId/curriculum" element={<TutorCurriculum />} />
                <Route path="courses/:courseId/live" element={<TutorLiveClasses />} />
                <Route path="courses/:courseId/live-classes" element={<TutorLiveClasses />} />
                <Route path="courses/:id/analytics" element={<TutorCourseAnalytics />} />
                <Route path="courses/:id/students" element={<TutorStudents />} />
                <Route path="materials" element={<TutorMaterials />} />
                <Route path="quizzes" element={<TutorQuizzes />} />
                <Route path="submissions" element={<TutorSubmissions />} />
                <Route path="students" element={<TutorStudents />} />
                <Route path="profile" element={<TutorProfile />} />
              </Route>

              {/* Standalone Full-Screen Host Live Classroom */}
              <Route path="/tutor/courses/:courseId/live/:liveClassId" element={<LiveClassroom />} />
              <Route path="/tutor/live/:liveClassId" element={<LiveClassroom />} />
              <Route path="/tutor/classroom/:sessionId" element={<InternalClassroom />} />
            </Route>

            {/* Admin Protected Routes (under /admin and /cpanel alias) */}
            <Route element={<AdminRoute />}>
              <Route path="/admin/classroom/:sessionId" element={<InternalClassroom />} />
              <Route path="/admin" element={<AdminLayout />}>
                <Route index element={<Dashboard />} />
                <Route path="dashboard" element={<Dashboard />} />
                <Route path="class-sessions" element={<AdminClassSessions />} />
                <Route path="class-history" element={<AdminClassHistory />} />
                <Route path="tutor-permissions" element={<AdminTutorPermissions />} />
                <Route path="categories" element={<AdminCategories />} />
                <Route path="home-cms" element={<AdminHomeCMS />} />
                <Route path="instructors" element={<AdminInstructors />} />
                <Route path="learning-paths" element={<AdminLearningPaths />} />
                <Route path="testimonials" element={<AdminTestimonials />} />
                <Route path="faqs" element={<AdminFaqs />} />
                <Route path="resources" element={<AdminResources />} />
                <Route path="settings" element={<AdminSettings />} />
                <Route path="navigation" element={<AdminNavigation />} />
                <Route path="media" element={<AdminMedia />} />
                <Route path="audit-logs" element={<AdminAuditLogs />} />
                <Route path="placements" element={<AdminPlacements />} />
                <Route path="users" element={<AdminUsers />} />
                <Route path="enrollments" element={<AdminEnrollments />} />
                <Route path="batches" element={<AdminBatches />} />
                <Route path="events" element={<AdminEvents />} />
                <Route path="events/new" element={<AdminEventForm />} />
                <Route path="events/:id/edit" element={<AdminEventForm />} />
                <Route path="events/:id/registrations" element={<AdminEventRegistrations />} />
                <Route path="courses/:courseId/curriculum" element={<AdminCurriculum />} />
                <Route path="submissions" element={<AdminSubmissions />} />
                <Route path="grading" element={<Navigate to="/admin/submissions" replace />} />
              </Route>

              {/* /cpanel & /c-panel Alias Routes */}
              <Route path="/cpanel" element={<Navigate to="/admin" replace />} />
              <Route path="/cpanel/*" element={<Navigate to="/admin" replace />} />
              <Route path="/c-panel" element={<Navigate to="/admin" replace />} />
              <Route path="/c-panel/*" element={<Navigate to="/admin" replace />} />
            </Route>

            {/* Counsellor + Admin Shared Areas (CRM / Enquiries only) */}
            <Route element={<CounsellorRoute />}>
              <Route path="/admin" element={<AdminLayout />}>
                <Route path="crm" element={<AdminCrm />} />
                <Route path="enquiries" element={<AdminEnquiries />} />
              </Route>
            </Route>

            {/* 404 Catch-All */}
            <Route path="*" element={<NotFound />} />
          </Routes>
        </Suspense>
      </AuthProvider>
    </BrowserRouter>
  );
}

export default App;
