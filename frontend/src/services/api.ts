import axios from "axios";

const API = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "http://127.0.0.1:8000/api",
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