import { useEffect } from "react";
import { Outlet, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/useAuth";

export default function AdminRoute() {
  const { user, loading } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  useEffect(() => {
    if (loading) return;
    if (!user) {
      navigate("/login", { replace: true, state: { from: location } });
    } else if (user.role === "tutor" || user.role === "faculty") {
      navigate("/tutor", { replace: true });
    } else if (user.role === "counsellor") {
      navigate("/admin/crm", { replace: true });
    } else if (user.role !== "admin" && user.role !== "super_admin") {
      navigate("/student", { replace: true });
    }
  }, [user, loading, navigate, location]);

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div className="flex items-center gap-3 text-slate-600 font-semibold">
          <span className="animate-spin inline-block w-5 h-5 border-2 border-purple-600 border-t-transparent rounded-full" />
          Verifying administrative authorization...
        </div>
      </div>
    );
  }

  if (!user) return null;
  if (user.role === "tutor" || user.role === "faculty") return null;
  if (user.role === "counsellor") return null;
  if (user.role !== "admin" && user.role !== "super_admin") return null;

  return <Outlet />;
}
