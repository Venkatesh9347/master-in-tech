import axios from "axios";

// Backend API base URL.
//   - Local development: VITE_API_URL is set in the (gitignored) frontend/.env.
//   - Production:     VITE_API_URL MUST point at the HTTPS API origin and is
//                     injected at build time (see frontend/.env.example).
// Production builds are REJECTED at build time in vite.config.ts when
// VITE_API_URL is missing, so a deployed bundle can never silently call a
// local dev server. The runtime guard below is a second line of defence for
// builds produced outside the normal pipeline.
const configuredBaseUrl = import.meta.env.VITE_API_URL as string | undefined;

// Production guard: a deployed bundle must never start with an undefined API
// base URL. The build itself is already rejected in vite.config.ts when
// VITE_API_URL is missing; this is a second line of defence for bundles built
// outside the normal pipeline.
if (!configuredBaseUrl && import.meta.env.PROD) {
  throw new Error(
    "VITE_API_URL is not defined. A production build must be created with " +
      "VITE_API_URL pointing to the HTTPS API origin. See frontend/.env.example " +
      "and DEPLOYMENT.md."
  );
}

// The 127.0.0.1 fallback below is LOCAL-DEVELOPMENT ONLY: it appears in the
// source, but in `vite build` (production mode) this ternary folds to the
// else-branch and the minifier drops the dev string entirely, so a shipped
// bundle never contains it. The Laravel backend runs on :8001; OpenViking owns :8000.
const API = axios.create({
  baseURL:
    import.meta.env.MODE === "development"
      ? configuredBaseUrl || "http://127.0.0.1:8001/api"
      : (configuredBaseUrl as string),
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

// Automatically attach Sanctum token to authenticated requests
API.interceptors.request.use((config) => {
  const token = localStorage.getItem("access_token");

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

let isRevoking = false;

// Handle response errors (e.g. expired or invalid tokens / single active session revocation)
API.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const isLoginRequest = Boolean(error.config?.url?.includes("/login") || error.config?.url?.includes("/register"));
      const isSessionRevoked =
        error.response?.data?.code === "SESSION_REVOKED" ||
        (typeof error.response?.data?.message === "string" &&
          (error.response?.data?.message.toLowerCase().includes("another device") ||
            error.response?.data?.message.toLowerCase().includes("unauthenticated") ||
            error.response?.data?.message.toLowerCase().includes("session expired")));

      if (!isLoginRequest && !isRevoking) {
        isRevoking = true;
        localStorage.removeItem("access_token");
        const reason =
          error.response?.data?.message ||
          (isSessionRevoked
            ? "Your session has expired because your account was signed in on another device."
            : "Your session has expired. Please log in again.");
        sessionStorage.setItem("session_revoked_notice", reason);

        window.dispatchEvent(
          new CustomEvent("auth:session_revoked", {
            detail: { message: reason },
          })
        );

        if (!window.location.pathname.startsWith("/login")) {
          window.location.href = "/login?session_expired=1";
        }

        setTimeout(() => {
          isRevoking = false;
        }, 3000);
      } else if (localStorage.getItem("access_token") && !isLoginRequest) {
        localStorage.removeItem("access_token");
      }
    }
    return Promise.reject(error);
  }
);

export default API;