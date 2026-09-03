import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../context/useAuth";

export default function CompanyRoute() {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-950 flex items-center justify-center p-6 text-slate-400">
        <div className="flex items-center gap-3 font-semibold text-xs">
          <span className="animate-spin inline-block w-5 h-5 border-2 border-purple-600 border-t-transparent rounded-full" />
          Verifying corporate partner authorization...
        </div>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (user.role === "admin" || user.role === "super_admin") {
    return <Navigate to="/admin" replace />;
  }

  if (user.role === "tutor" || user.role === "faculty") {
    return <Navigate to="/tutor" replace />;
  }

  if (user.role !== "company" && user.role !== "recruiter") {
    return <Navigate to="/student" replace />;
  }

  return <Outlet />;
}
