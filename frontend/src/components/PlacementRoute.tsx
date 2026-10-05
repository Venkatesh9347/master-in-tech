import { useEffect } from "react";
import { Outlet, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/useAuth";

/**
 * Placement Operations Desk guard.
 *
 * Navigation UX only — the backend remains authoritative. Every endpoint under
 * /api/admin/placements/* is protected by EnsureUserIsPlacementStaff, so
 * bypassing this component grants nothing.
 *
 * This guard exists because without it a placement_advisor fell through
 * Login.tsx to /student, StudentRoute bounced them back to /login, and the two
 * redirected to each other indefinitely.
 */
export default function PlacementRoute() {
  const { user, loading } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  useEffect(() => {
    if (loading) return;
    if (!user) {
      navigate("/login", { replace: true, state: { from: location } });
    } else if (user.role === "admin" || user.role === "super_admin") {
      navigate("/admin", { replace: true });
    } else if (user.role === "tutor" || user.role === "faculty") {
      navigate("/tutor", { replace: true });
    } else if (
      user.role === "counsellor" ||
      user.role === "telecaller" ||
      user.role === "course_advisor"
    ) {
      navigate("/admin/crm", { replace: true });
    } else if (user.role === "company" || user.role === "recruiter") {
      navigate("/company", { replace: true });
    } else if (user.role !== "placement_advisor") {
      navigate("/student", { replace: true });
    }
  }, [user, loading, navigate, location]);

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div className="flex items-center gap-3 text-slate-600 font-semibold">
          <span className="animate-spin inline-block w-5 h-5 border-2 border-purple-600 border-t-transparent rounded-full" />
          Verifying placement authorization...
        </div>
      </div>
    );
  }

  if (!user) return null;
  if (user.role === "admin" || user.role === "super_admin") return null;
  if (user.role === "tutor" || user.role === "faculty") return null;
  if (
    user.role === "counsellor" ||
    user.role === "telecaller" ||
    user.role === "course_advisor"
  )
    return null;
  if (user.role === "company" || user.role === "recruiter") return null;
  if (user.role !== "placement_advisor") return null;

  return <Outlet />;
}