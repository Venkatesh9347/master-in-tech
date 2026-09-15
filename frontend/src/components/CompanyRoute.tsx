import { useEffect } from "react";
import { Outlet, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/useAuth";

export default function CompanyRoute() {
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
    } else if (user.role !== "company" && user.role !== "recruiter") {
      navigate("/student", { replace: true });
    }
  }, [user, loading, navigate, location]);

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

  if (!user) return null;
  if (user.role === "admin" || user.role === "super_admin") return null;
  if (user.role === "tutor" || user.role === "faculty") return null;
  if (user.role !== "company" && user.role !== "recruiter") return null;

  return <Outlet />;
}
