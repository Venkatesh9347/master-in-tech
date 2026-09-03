import { Navigate, Outlet } from "react-router-dom";
import { useAuth } from "../context/useAuth";

export default function StudentRoute() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div className="flex items-center gap-3 text-slate-600 font-semibold">
          <span className="animate-spin inline-block w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full" />
          Loading learning portal...
        </div>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (user.role === "admin" || user.role === "super_admin") {
    return <Navigate to="/admin" replace />;
  }

  if (user.role === "tutor" || user.role === "faculty") {
    return <Navigate to="/tutor" replace />;
  }

  if (user.role === "company" || user.role === "recruiter") {
    return <Navigate to="/company" replace />;
  }

  if (user.role !== "student") {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
}
