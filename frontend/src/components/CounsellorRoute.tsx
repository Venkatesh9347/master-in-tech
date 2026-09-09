import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../context/useAuth";
import { canAccessArea } from "../lib/permissions";

export default function CounsellorRoute() {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div className="flex items-center gap-3 text-slate-600 font-semibold">
          <span className="animate-spin inline-block w-5 h-5 border-2 border-purple-600 border-t-transparent rounded-full" />
          Verifying CRM & Admissions authorization...
        </div>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  // Counsellor access is limited to CRM / enquiry / lead areas.
  if (user.role === "tutor" || user.role === "faculty") {
    return <Navigate to="/tutor" replace />;
  }

  if (user.role === "student") {
    return <Navigate to="/student" replace />;
  }

  const allowed = canAccessArea(user, "crm");

  if (!allowed) {
    return <Navigate to="/login" replace />;
  }

  // Only permit CRM / enquiry routes for this guard.
  const path = location.pathname;
  const isCrmArea =
    path.startsWith("/admin/crm") ||
    path.startsWith("/admin/enquiries");

  if (!isCrmArea) {
    return <Navigate to="/admin/crm" replace />;
  }

  return <Outlet />;
}
