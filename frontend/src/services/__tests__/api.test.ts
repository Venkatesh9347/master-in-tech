import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => {
  type ConfigLike = { headers: Record<string, unknown> };

  const requestHandlers: Array<(config: Record<string, unknown>) => ConfigLike> = [];
  const responseErrorHandlers: Array<(error: unknown) => Promise<unknown>> = [];

  const instanceCall = vi.fn((config: unknown) => Promise.resolve(config));

  const apiInstance = Object.assign((config: unknown) => instanceCall(config), {
    interceptors: {
      request: {
        use: (handler: (config: Record<string, unknown>) => ConfigLike) => {
          requestHandlers.push(handler);
        },
      },
      response: {
        use: (_ok: unknown, errorHandler: (error: unknown) => Promise<unknown>) => {
          responseErrorHandlers.push(errorHandler);
        },
      },
    },
  });

  return { requestHandlers, responseErrorHandlers, apiInstance, instanceCall };
});

vi.mock("axios", () => ({
  default: {
    create: () => mocks.apiInstance,
  },
}));

import API, {
  classifyApiError,
  isAuthError,
  isExemptUrl,
  isNetworkError,
  isServerError,
  isSessionRevoked,
} from "../api";

const error = (overrides: Record<string, unknown>) =>
  ({ isAxiosError: true, config: { method: "get" }, ...overrides }) as unknown;

const handler = () => mocks.responseErrorHandlers[0];

const requestHandler = () => mocks.requestHandlers[0];

describe("api error classification", () => {
  it("classifies a missing response as a network error (never logout)", () => {
    const err = error({ response: undefined, code: "ECONNABORTED" });

    expect(isNetworkError(err)).toBe(true);
    expect(isServerError(err)).toBe(false);
    expect(isAuthError(err)).toBe(false);
    expect(classifyApiError(err)).toMatchObject({
      kind: "network",
      isAuthFailure: false,
      isSessionRevoked: false,
    });
  });

  it("classifies a 5xx as a server error (never logout)", () => {
    const err = error({ response: { status: 500, data: {} } });

    expect(isServerError(err)).toBe(true);
    expect(isAuthError(err)).toBe(false);
    expect(classifyApiError(err)).toMatchObject({
      kind: "server",
      status: 500,
      isAuthFailure: false,
    });
  });

  it("classifies a 401 as a confirmed auth failure", () => {
    const err = error({ response: { status: 401, data: { message: "Unauthenticated." } } });

    expect(isAuthError(err)).toBe(true);
    expect(classifyApiError(err)).toMatchObject({
      kind: "unauthorized",
      status: 401,
      isAuthFailure: true,
    });
  });

  it("detects session revocation via code or message", () => {
    expect(isSessionRevoked(error({ response: { status: 401, data: { code: "SESSION_REVOKED" } } }))).toBe(
      true
    );
    expect(
      isSessionRevoked(
        error({ response: { status: 401, data: { message: "signed in on another device" } } })
      )
    ).toBe(true);
    expect(isSessionRevoked(error({ response: { status: 401, data: { message: "oh no" } } }))).toBe(
      false
    );
  });

  it("exempts login and public endpoints from logout logic", () => {
    expect(isExemptUrl("/login")).toBe(true);
    expect(isExemptUrl("/auth/google")).toBe(true);
    expect(isExemptUrl("/user")).toBe(false);
    expect(isExemptUrl("/health")).toBe(true);
  });
});

describe("api request interceptor", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  it("attaches the Bearer token when present", () => {
    localStorage.setItem("access_token", "token-123");

    const config: Record<string, unknown> = { headers: {} };
    const next = requestHandler()(config);
    const headers = next.headers as Record<string, unknown>;

    expect(headers.Authorization).toBe("Bearer token-123");
  });

  it("does not attach a token when absent", () => {
    const config: Record<string, unknown> = { headers: {} };
    const next = requestHandler()(config);
    const headers = next.headers as Record<string, unknown>;

    expect(headers.Authorization).toBeUndefined();
  });
});

describe("api response interceptor (R5 token-safety)", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    mocks.instanceCall.mockClear();
  });

  it("retries an idempotent GET on a network failure and keeps the token", async () => {
    localStorage.setItem("access_token", "token-123");
    const err = error({
      config: { method: "get", url: "/user" },
      response: undefined,
    });

    await handler()(err);

    expect(mocks.instanceCall).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem("access_token")).toBe("token-123");
    expect(sessionStorage.getItem("session_revoked_notice")).toBeNull();
  });

  it("keeps a valid token when a network failure rejects (retry exhausted)", async () => {
    localStorage.setItem("access_token", "token-123");
    const err = error({
      config: { method: "get", url: "/user", _retried: true },
      response: undefined,
    });

    await expect(handler()(err)).rejects.toBe(err);
    expect(localStorage.getItem("access_token")).toBe("token-123");
    expect(sessionStorage.getItem("session_revoked_notice")).toBeNull();
  });

  it("keeps a valid token on a 5xx server error", async () => {
    localStorage.setItem("access_token", "token-123");
    const err = error({
      config: { method: "get", url: "/user", _retried: true },
      response: { status: 500, data: {} },
    });

    await expect(handler()(err)).rejects.toBe(err);
    expect(localStorage.getItem("access_token")).toBe("token-123");
    expect(sessionStorage.getItem("session_revoked_notice")).toBeNull();
  });

  it("clears the token and notifies listeners on confirmed session revocation", async () => {
    localStorage.setItem("access_token", "token-123");

    // jsdom does not implement full navigation; point the URL at /login so the
    // redirect branch is skipped while the logout side-effects still run.
    window.history.pushState({}, "", "/login");

    const firedEvents: string[] = [];
    const listener = (event: Event) => firedEvents.push((event as CustomEvent).detail?.message ?? "");
    window.addEventListener("auth:session_revoked", listener);

    const err = error({
      config: { method: "get", url: "/user", _retried: true },
      response: {
        status: 401,
        data: {
          code: "SESSION_REVOKED",
          message: "Your session has expired because your account was signed in on another device.",
        },
      },
    });

    await expect(handler()(err)).rejects.toBe(err);
    expect(localStorage.getItem("access_token")).toBeNull();
    expect(sessionStorage.getItem("session_revoked_notice")).toContain("another device");
    expect(firedEvents).toHaveLength(1);
    expect(firedEvents[0]).toContain("another device");

    window.removeEventListener("auth:session_revoked", listener);
  });

  it("never clears a token for a 401 on login endpoints", async () => {
    localStorage.setItem("access_token", "token-123");
    const err = error({ config: { method: "post", url: "/login" }, response: { status: 401, data: {} } });

    await expect(handler()(err)).rejects.toBe(err);
    expect(localStorage.getItem("access_token")).toBe("token-123");
  });

  it("retries an idempotent GET once on network failure", async () => {
    localStorage.setItem("access_token", "token-123");
    const err = error({
      config: { method: "get", url: "/courses", headers: {} },
      response: undefined,
    });

    await handler()(err);

    expect(mocks.instanceCall).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem("access_token")).toBe("token-123");

    const retried = error({
      config: { method: "get", url: "/courses", _retried: true },
      response: undefined,
    });

    await expect(handler()(retried)).rejects.toBe(retried);
    expect(mocks.instanceCall).toHaveBeenCalledTimes(1);
  });

  it("does not retry non-idempotent writes", async () => {
    const err = error({
      config: { method: "post", url: "/payments", headers: {} },
      response: undefined,
    });

    await expect(handler()(err)).rejects.toBe(err);
    expect(mocks.instanceCall).not.toHaveBeenCalled();
  });
});

describe("api instance", () => {
  it("exposes the axios instance as default export", () => {
    expect(API).toBe(mocks.apiInstance);
  });
});