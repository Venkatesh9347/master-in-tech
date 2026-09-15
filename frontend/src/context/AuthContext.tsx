import { useEffect, useState, useCallback, useRef } from "react";
import type { ReactNode } from "react";
import { useLocation } from "react-router-dom";
import API, { classifyApiError } from "../services/api";
import { AuthContext, isDashboardRoute } from "./auth-context";
import type { User, GoogleAuthPendingSession, GoogleAuthPayload } from "./auth-context";

export function AuthProvider({ children }: { children: ReactNode }) {
  const location = useLocation();
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(() => Boolean(localStorage.getItem("access_token")));

  const heartbeatTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const inFlightRef = useRef<boolean>(false);

  // Stop heartbeat timer utility
  const stopHeartbeat = useCallback(() => {
    if (heartbeatTimerRef.current) {
      clearInterval(heartbeatTimerRef.current);
      heartbeatTimerRef.current = null;
    }
  }, []);

  // Listen for global session revocation events
  useEffect(() => {
    const handleRevoked = () => {
      stopHeartbeat();
      setUser(null);
      setLoading(false);
      localStorage.removeItem("access_token");
    };

    window.addEventListener("auth:session_revoked", handleRevoked);
    return () => {
      window.removeEventListener("auth:session_revoked", handleRevoked);
    };
  }, [stopHeartbeat]);

  // Initial user fetch on page load
  useEffect(() => {
    const token = localStorage.getItem("access_token");

    if (!token) {
      setLoading(false);
      return;
    }

    API.get<User>("/user")
      .then((response) => setUser(response.data))
      .catch((err: unknown) => {
        // R5: a network blip or transient 5xx must NOT clear a valid token.
        // Only a confirmed auth failure (401) invalidates the local session.
        if (classifyApiError(err).isAuthFailure) {
          localStorage.removeItem("access_token");
          setUser(null);
        }
      })
      .finally(() => setLoading(false));
  }, []);

  // Single-active-session heartbeat: checks session every 30 seconds on dashboard routes
  useEffect(() => {
    const token = localStorage.getItem("access_token");
    const onDashboard = isDashboardRoute(location.pathname);

    // If not authenticated or not on a dashboard route, stop any active heartbeat timer
    if (!user || !token || !onDashboard) {
      stopHeartbeat();
      return;
    }

    // If a heartbeat timer is already running (e.g. navigating between dashboard pages), keep it active without duplicate timers
    if (heartbeatTimerRef.current) {
      return;
    }

    const checkSessionHeartbeat = async () => {
      if (inFlightRef.current) return;
      const currentToken = localStorage.getItem("access_token");
      if (!currentToken) {
        stopHeartbeat();
        return;
      }

      inFlightRef.current = true;
      try {
        await API.get<User>("/user");
      } catch (err: unknown) {
        const error = err as { response?: { status?: number; data?: { code?: string; message?: string } } };
        if (error.response?.status === 401) {
          stopHeartbeat();
          localStorage.removeItem("access_token");
          setUser(null);
          const reason =
            error.response?.data?.message ||
            "Your session has expired because your account was signed in on another device.";
          sessionStorage.setItem("session_revoked_notice", reason);

          window.dispatchEvent(
            new CustomEvent("auth:session_revoked", {
              detail: { message: reason },
            })
          );

          if (!window.location.pathname.startsWith("/login")) {
            window.location.href = "/login?session_expired=1";
          }
        }
      } finally {
        inFlightRef.current = false;
      }
    };

    heartbeatTimerRef.current = setInterval(checkSessionHeartbeat, 30000);

    return () => {
      // Clean up on component unmount
    };
  }, [user, location.pathname, stopHeartbeat]);

  // Clean up timer on full unmount
  useEffect(() => {
    return () => {
      stopHeartbeat();
    };
  }, [stopHeartbeat]);

  const login = useCallback(async (email: string, password: string) => {
    const response = await API.post<{ access_token: string; user: User }>("/login", {
      email,
      password,
    });

    localStorage.setItem("access_token", response.data.access_token);
    setUser(response.data.user);

    return response.data.user;
  }, []);

  const initiateGoogleAuth = useCallback(async (
    payload: string | GoogleAuthPayload
  ): Promise<GoogleAuthPendingSession> => {
    const data = typeof payload === "string" ? { credential: payload } : payload;
    const response = await API.post<GoogleAuthPendingSession>("/auth/google", data);
    return response.data;
  }, []);

  const initiateMobileAuth = useCallback(async (phone: string): Promise<GoogleAuthPendingSession> => {
    const response = await API.post<GoogleAuthPendingSession>("/auth/mobile/send-otp", {
      phone,
    });
    return response.data;
  }, []);

  const verifyOtp = useCallback(async (tempToken: string, otp: string): Promise<User> => {
    const response = await API.post<{ access_token: string; user: User }>("/auth/otp/verify", {
      temp_token: tempToken,
      otp,
    });

    localStorage.setItem("access_token", response.data.access_token);
    setUser(response.data.user);

    return response.data.user;
  }, []);

  const resendOtp = useCallback(async (tempToken: string): Promise<GoogleAuthPendingSession> => {
    const response = await API.post<GoogleAuthPendingSession>("/auth/otp/resend", {
      temp_token: tempToken,
    });
    return response.data;
  }, []);

  const logout = useCallback(async () => {
    stopHeartbeat();
    // Fire-and-forget server logout; do not block UI on network. The local
    // session is cleared immediately so route guards see the correct state.
    API.post("/logout").catch(() => {});
    localStorage.removeItem("access_token");
    setUser(null);
  }, [stopHeartbeat]);

  return (
    <AuthContext.Provider
      value={{
        user,
        loading,
        login,
        initiateGoogleAuth,
        initiateMobileAuth,
        verifyOtp,
        resendOtp,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}
