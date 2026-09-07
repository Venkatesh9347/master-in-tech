import { useContext } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import type { User } from "../auth-context";

const mocks = vi.hoisted(() => {
  const instanceCall = vi.fn((config: unknown) => Promise.resolve(config));

  const apiInstance = Object.assign(
    (config: unknown) => instanceCall(config),
    {
      interceptors: {
        request: { use: (_handler: unknown) => {} },
        response: { use: (_ok: unknown, _err: unknown) => {} },
      },
      get: vi.fn(),
      post: vi.fn(),
    }
  );

  return { apiInstance, instanceCall };
});

vi.mock("axios", () => ({
  default: {
    create: () => mocks.apiInstance,
  },
}));

import { AuthProvider } from "../AuthContext";
import { AuthContext, isDashboardRoute } from "../auth-context";

const user: User = { id: 1, name: "Test User", email: "test@example.com" };

const networkError = {
  isAxiosError: true,
  config: { method: "get", url: "/user" },
  response: undefined,
} as unknown;

const authError = {
  isAxiosError: true,
  config: { method: "get", url: "/user" },
  response: { status: 401, data: { code: "SESSION_REVOKED", message: "Unauthenticated." } },
} as unknown;

function Probe() {
  const value = useContext(AuthContext);
  return (
    <span data-testid="state">
      {(value?.user?.email ?? "guest") + "|" + (value?.loading ? "loading" : "ready")}
    </span>
  );
}

function renderProvider() {
  return render(
    <MemoryRouter initialEntries={["/"]}>
      <AuthProvider>
        <Probe />
      </AuthProvider>
    </MemoryRouter>
  );
}

describe("AuthProvider boot", () => {
  afterEach(() => {
    cleanup();
  });

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    mocks.apiInstance.get.mockReset();
  });

  it("loads the user when the token is valid", async () => {
    localStorage.setItem("access_token", "token-123");
    mocks.apiInstance.get.mockResolvedValueOnce({ data: user });

    renderProvider();

    await waitFor(() => {
      expect(mocks.apiInstance.get).toHaveBeenCalledWith("/user");
    });
    expect((await screen.findByTestId("state")).textContent).toBe("test@example.com|ready");
    expect(localStorage.getItem("access_token")).toBe("token-123");
  });

  it("keeps a valid token when the boot request fails with a network error (R5)", async () => {
    localStorage.setItem("access_token", "token-123");
    mocks.apiInstance.get.mockRejectedValueOnce(networkError);

    renderProvider();

    await waitFor(() => {
      expect(mocks.apiInstance.get).toHaveBeenCalledWith("/user");
    });
    expect((await screen.findByTestId("state")).textContent).toBe("guest|ready");
    expect(localStorage.getItem("access_token")).toBe("token-123");
  });

  it("keeps a valid token when the boot request fails with a 5xx (R5)", async () => {
    localStorage.setItem("access_token", "token-123");
    mocks.apiInstance.get.mockRejectedValueOnce({
      isAxiosError: true,
      config: { method: "get", url: "/user" },
      response: { status: 500, data: {} },
    });

    renderProvider();

    await waitFor(() => {
      expect(mocks.apiInstance.get).toHaveBeenCalledWith("/user");
    });
    expect(localStorage.getItem("access_token")).toBe("token-123");
  });

  it("clears the token when the boot request confirms an auth failure (401)", async () => {
    localStorage.setItem("access_token", "token-123");
    mocks.apiInstance.get.mockRejectedValueOnce(authError);

    renderProvider();

    await waitFor(() => {
      expect(mocks.apiInstance.get).toHaveBeenCalledWith("/user");
    });
    expect((await screen.findByTestId("state")).textContent).toBe("guest|ready");
    expect(localStorage.getItem("access_token")).toBeNull();
  });

  it("does not boot when no token is stored", async () => {
    renderProvider();

    expect((await screen.findByTestId("state")).textContent).toBe("guest|ready");
    expect(mocks.apiInstance.get).not.toHaveBeenCalled();
  });
});

describe("isDashboardRoute heartbeat coverage", () => {
  it("covers admin, tutor, student and company dashboards", () => {
    expect(isDashboardRoute("/admin/dashboard")).toBe(true);
    expect(isDashboardRoute("/tutor/courses")).toBe(true);
    expect(isDashboardRoute("/student/checkout")).toBe(true);
    expect(isDashboardRoute("/company/dashboard")).toBe(true);
    expect(isDashboardRoute("/login")).toBe(false);
    expect(isDashboardRoute("/")).toBe(false);
  });
});