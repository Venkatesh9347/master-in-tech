import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../context/useAuth";

export default function CounsellorRoute() {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div className="flex items-center gap-3 text-slate-600 font-semibold">
          <span className="animate-spin inline-block w-5 h-5 border-2 border-purple-600 border-t-transparent rounded-full" />
          Verifying authorization...
        </div>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (!user.role || !["admin", "super_admin", "counsellor"].includes(user.role)) {
    return <Navigate to={user.role === "tutor" || user.role === "faculty" ? "/tutor" : "/student"} replace />;
  }

  return <Outlet />;
}