import axios from "axios";
import type { AxiosError, InternalAxiosRequestConfig } from "axios";

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

/**
 * Classification helpers (exported for unit tests).
 *
 * R5 rule: network failures and 5xx server errors must NEVER clear a valid
 * token or force a logout. Only a CONFIRMED 401 (a real authentication
 * failure / session revocation) may invalidate the local session.
 */

export type ApiErrorKind = "network" | "unauthorized" | "server" | "client" | "unknown";

export interface ClassifiedApiError {
  kind: ApiErrorKind;
  status?: number;
  code?: string;
  message?: string;
  isAuthFailure: boolean;
  isSessionRevoked: boolean;
}

const REVOCATION_HINTS = ["another device", "unauthenticated", "session expired"];

export const isAxiosErrorLike = (error: unknown): error is AxiosError =>
  Boolean(error && (error as AxiosError).isAxiosError);

export const isNetworkError = (error: unknown): boolean =>
  isAxiosErrorLike(error) && !(error as AxiosError).response;

export const isServerError = (error: unknown): boolean =>
  isAxiosErrorLike(error) && ((error as AxiosError).response?.status ?? 0) >= 500;

export const isAuthError = (error: unknown): boolean =>
  isAxiosErrorLike(error) && (error as AxiosError).response?.status === 401;

/**
 * Legacy broad matcher, kept for backward compatibility (unit tests).
 * Do NOT use for new request/response decisions: substring matching
 * misclassifies authenticated endpoints (e.g. GET /api/tutor/courses,
 * POST /api/events/{id}/register). Use {@link isAuthFlowUrl},
 * {@link isPublicCatalogRead} and {@link shouldSkipAuthHeader} instead.
 */
export const isExemptUrl = (url?: string): boolean =>
  Boolean(
    url &&
      (url.includes("/login") ||
        url.includes("/register") ||
        url.includes("/auth/") ||
        url.includes("/enquiries") ||
        url.includes("/health") ||
        url.includes("/courses") ||
        url.includes("/course-categories") ||
        url.includes("/public/") ||
        url.includes("/events") ||
        url.includes("/placements"))
  );

/**
 * Auth-flow endpoints that never carry a user session (login, registration,
 * OAuth/OTP exchanges, health checks). A 401 here is a credential problem,
 * never a session revocation.
 *
 * Exact matching is deliberate: substring matching would misclassify
 * authenticated endpoints such as POST /api/events/{id}/register.
 */
export const isAuthFlowUrl = (url?: string): boolean =>
  Boolean(
    url &&
      (url === "/login" ||
        url === "/register" ||
        url === "/health" ||
        url.startsWith("/auth/"))
  );

export interface PublicReadConfig {
  method?: string;
  url?: string;
}

/**
 * Anonymous public catalog reads that must succeed without credentials.
 *
 * Narrowly scoped to exact collection paths with GET/HEAD only. Substring
 * matching is deliberately NOT used: authenticated endpoints merely
 * containing these segments (GET /api/tutor/courses, POST
 * /api/courses/{id}/enroll, GET /api/courses/{id}/sections, POST
 * /api/events/{id}/register, GET /api/placements/my-applications, exact
 * POST /api/enquiries vs /api/admin/enquiries/*) must always keep their
 * Bearer token. A broad substring exemption provably broke tutor
 * dashboards (GET /api/tutor/courses sent without Authorization -> 401).
 */
export const isPublicCatalogRead = (config?: PublicReadConfig): boolean => {
  const method = (config?.method || "get").toLowerCase();
  if (method !== "get" && method !== "head") return false;

  const url = config?.url || "";
  return (
    url === "/courses" ||
    url === "/course-categories" ||
    url === "/events" ||
    url === "/events/upcoming" ||
    url === "/events/past" ||
    url === "/placements/settings" ||
    url === "/placements/opportunities" ||
    url.startsWith("/public/")
  );
};

/**
 * Per-request opt-out for the Authorization header. True only for auth
 * flows, the exact public POST /enquiries lead form, and anonymous public
 * catalog reads. Everything else (including authenticated /courses/*,
 * /events/*, /placements/* and /admin/enquiries/* calls) keeps its token.
 */
export const shouldSkipAuthHeader = (config?: PublicReadConfig): boolean => {
  const url = config?.url || "";
  if (isAuthFlowUrl(url)) return true;
  if (url === "/enquiries") return true;
  return isPublicCatalogRead(config);
};

export const isSessionRevoked = (error: unknown): boolean => {
  if (!isAuthError(error)) return false;

  const data = (error as AxiosError).response?.data as
    | { code?: string; message?: string }
    | undefined;

  if (data?.code === "SESSION_REVOKED") return true;

  if (typeof data?.message === "string") {
    const normalized = data.message.toLowerCase();

    return REVOCATION_HINTS.some((hint) => normalized.includes(hint));
  }

  return false;
};

export const classifyApiError = (error: unknown): ClassifiedApiError => {
  const auth = isAuthError(error);
  const network = isNetworkError(error);
  const server = isServerError(error);

  const data = isAxiosErrorLike(error)
    ? ((error as AxiosError).response?.data as { code?: string; message?: string } | undefined)
    : undefined;

  let kind: ApiErrorKind;
  if (network) kind = "network";
  else if (auth) kind = "unauthorized";
  else if (server) kind = "server";
  else kind = isAxiosErrorLike(error) ? "client" : "unknown";

  return {
    kind,
    status: isAxiosErrorLike(error) ? (error as AxiosError).response?.status : undefined,
    code: data?.code,
    message: data?.message,
    isAuthFailure: auth && !network && !server,
    isSessionRevoked: auth && isSessionRevoked(error),
  };
};

// Automatically attach Sanctum token to authenticated requests.
// Anonymous public catalog reads skip the header so they never depend on
// (stale) token state; every other request keeps its Bearer token.
API.interceptors.request.use((config) => {
  if (shouldSkipAuthHeader({ method: config.method, url: config.url })) {
    // Never leak a stale token on anonymous reads (including retries that
    // reuse the same config object).
    if (config.headers.Authorization) {
      delete config.headers.Authorization;
    }
    return config;
  }

  const token = localStorage.getItem("access_token");

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

let isRevoking = false;

interface RetryableConfig extends InternalAxiosRequestConfig {
  _retried?: boolean;
}

/**
 * Idempotent GET/HEAD requests may be retried once on network failure or a
 * transient 5xx. Never retried for non-idempotent writes or auth failures.
 */
const canRetry = (error: unknown): boolean => {
  if (!isAxiosErrorLike(error)) return false;

  const config = (error as AxiosError).config as RetryableConfig | undefined;
  if (!config || config._retried) return false;

  const method = (config.method || "get").toLowerCase();

  return (method === "get" || method === "head") && (isNetworkError(error) || isServerError(error));
};

/**
 * Handle response errors.
 *
 *  - Network failures / 5xx: reject WITHOUT touching the token (R5). A flaky
 *    connection or transient server error must not sign an authenticated user out.
 *  - Confirmed 401 (real auth failure / single-active-session revocation):
 *    clear the local token, record the reason, notify listeners and redirect
 *    to login. Preserves the existing single-active-session behavior.
 */
API.interceptors.response.use(
  (response) => response,
  (error) => {
    if (canRetry(error)) {
      const config = (error as AxiosError).config as RetryableConfig;
      config._retried = true;

      return API(config);
    }

    const classified = classifyApiError(error);

    if (classified.isAuthFailure) {
      const requestUrl = isAxiosErrorLike(error)
        ? (error as AxiosError).config?.url
        : undefined;
      const requestMethod = isAxiosErrorLike(error)
        ? (error as AxiosError).config?.method
        : undefined;

      // Only auth flows and anonymous public reads are exempt from session
      // revocation. A 401 on any authenticated endpoint (including
      // /tutor/courses or /courses/{id}/enroll) is a real session failure.
      const isLoginRequest =
        isAuthFlowUrl(requestUrl) ||
        isPublicCatalogRead({ method: requestMethod, url: requestUrl }) ||
        requestUrl === "/enquiries";

      if (!isLoginRequest && !isRevoking) {
        isRevoking = true;
        localStorage.removeItem("access_token");

        const reason =
          classified.message ||
          (classified.isSessionRevoked
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