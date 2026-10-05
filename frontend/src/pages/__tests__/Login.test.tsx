import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, cleanup, fireEvent } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

const mockNavigate = vi.fn();
vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => mockNavigate };
});

const mockLogin = vi.fn();
const mockInitiateGoogleAuth = vi.fn();
const mockInitiateMobileAuth = vi.fn();

const authState = { user: null as any, loading: false };

vi.mock("../../context/useAuth", () => ({
  useAuth: () => ({
    user: authState.user,
    loading: authState.loading,
    login: mockLogin,
    initiateGoogleAuth: mockInitiateGoogleAuth,
    initiateMobileAuth: mockInitiateMobileAuth,
  }),
}));

vi.mock("../../components/Navbar", () => ({ default: () => <div>NAVBAR</div> }));
vi.mock("../../components/Footer", () => ({ default: () => <div>FOOTER</div> }));
vi.mock("../../components/auth/GoogleAuthButton", () => ({
  default: () => <div>GOOGLE</div>,
}));
vi.mock("../../components/auth/OtpVerificationModal", () => ({
  default: () => null,
}));
vi.mock("../../components/PublicAccessGateModal", () => ({
  default: () => null,
}));

import Login from "../Login";

describe("Login deterministic navigation", () => {
  beforeEach(() => {
    mockNavigate.mockClear();
    mockLogin.mockReset();
    authState.user = null;
    authState.loading = false;
    localStorage.clear();
  });
  afterEach(() => cleanup());

  it("redirects already-authenticated admin to /admin via state-driven effect", async () => {
    authState.user = { id: 1, name: "Admin", email: "admin@example.com", role: "admin" } as any;
    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/admin", expect.objectContaining({ replace: true })));
  });

  it("redirects already-authenticated student to /student", async () => {
    authState.user = { id: 2, name: "Student", email: "student@example.com", role: "student" } as any;
    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/student", expect.objectContaining({ replace: true })));
  });

  it("does not redirect while auth is loading", async () => {
    authState.user = null;
    authState.loading = true;
    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>
    );
    await new Promise((r) => setTimeout(r, 100));
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("successful login relies on state-driven redirect, not immediate navigate", async () => {
    const studentUser = { id: 3, name: "Student", email: "student@example.com", role: "student" } as any;
    // login resolves but does not immediately navigate; navigation happens via auth state
    mockLogin.mockResolvedValue(studentUser);

    // Simulate that after login, authState will be updated (like AuthContext would)
    // For this unit test, we manually update authState after login resolves
    mockLogin.mockImplementation(async () => {
      authState.user = studentUser;
      return studentUser;
    });

    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>
    );

    fireEvent.change(screen.getByPlaceholderText("faculty@masterintech.com"), { target: { value: "student@example.com" } });
    fireEvent.change(screen.getByPlaceholderText("••••••••"), { target: { value: "password" } });
    fireEvent.click(screen.getByText("Staff / Instructor Sign In →"));

    await waitFor(() => expect(mockLogin).toHaveBeenCalled());
    // The deterministic effect should eventually navigate
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/student", expect.objectContaining({ replace: true })));
  });

  it("failed login stays on /login and shows error", async () => {
    mockLogin.mockRejectedValue({ response: { data: { message: "Invalid" } } });

    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>
    );

    fireEvent.change(screen.getByPlaceholderText("faculty@masterintech.com"), { target: { value: "student@example.com" } });
    fireEvent.change(screen.getByPlaceholderText("••••••••"), { target: { value: "password" } });
    fireEvent.click(screen.getByText("Staff / Instructor Sign In →"));

    await waitFor(() => expect(screen.getByText("Invalid")).toBeTruthy());
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it.each([
    ["counsellor", "/admin/crm"],
    ["telecaller", "/admin/crm"],
    ["course_advisor", "/admin/crm"],
  ] as const)("redirects already-authenticated %s to %s (no redirect loop)", async (role, path) => {
    authState.user = { id: 10, name: "Staff", email: "staff@example.com", role } as any;
    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith(path, expect.objectContaining({ replace: true })));
  });

  it("returns telecaller to /admin/enquiries deep link after login", async () => {
    authState.user = { id: 11, name: "Tele", email: "tele@example.com", role: "telecaller" } as any;
    render(
      <MemoryRouter
        initialEntries={[{ pathname: "/login", state: { from: { pathname: "/admin/enquiries" } } }]}
      >
        <Login />
      </MemoryRouter>
    );
    await waitFor(() =>
      expect(mockNavigate).toHaveBeenCalledWith("/admin/enquiries", expect.objectContaining({ replace: true }))
    );
  });

  // -------------------------------------------------------------------
  // placement_advisor must land on its own desk.
  //
  // The role used to fall through to the student fallback, so /student
  // rejected it and redirected to /login, producing an infinite loop.
  // -------------------------------------------------------------------

  it("redirects placement_advisor to /placement", async () => {
    authState.user = { id: 12, name: "Placement", email: "placement@example.com", role: "placement_advisor" };
    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/placement", expect.objectContaining({ replace: true })));
    expect(mockNavigate).not.toHaveBeenCalledWith("/student", expect.anything());
    expect(mockNavigate).not.toHaveBeenCalledWith("/login", expect.anything());
  });

  it("does not honour a /admin deep link for placement_advisor", async () => {
    authState.user = { id: 13, name: "Placement", email: "placement@example.com", role: "placement_advisor" };
    render(
      <MemoryRouter initialEntries={[{ pathname: "/login", state: { from: { pathname: "/admin" } } }]}>
        <Login />
      </MemoryRouter>
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/placement", expect.objectContaining({ replace: true })));
    expect(mockNavigate).not.toHaveBeenCalledWith("/admin", expect.anything());
  });
});
