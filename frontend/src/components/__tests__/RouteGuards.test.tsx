import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter, Routes, Route } from "react-router-dom";

const mockNavigate = vi.fn();
vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

import AdminRoute from "../AdminRoute";
import StudentRoute from "../StudentRoute";
import TutorRoute from "../TutorRoute";
import { AuthContext } from "../../context/auth-context";
import type { User } from "../../context/auth-context";

const adminUser: User = { id: 1, name: "Admin", email: "admin@example.com", role: "admin" };
const tutorUser: User = { id: 2, name: "Tutor", email: "tutor@example.com", role: "tutor" };
const studentUser: User = { id: 3, name: "Student", email: "student@example.com", role: "student" };

function renderWithAuth(ui: React.ReactNode, authValue: { user: User | null; loading: boolean }, initialPath = "/admin") {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <AuthContext.Provider value={{ user: authValue.user, loading: authValue.loading, login: vi.fn(), initiateGoogleAuth: vi.fn(), initiateMobileAuth: vi.fn(), verifyOtp: vi.fn(), resendOtp: vi.fn(), logout: vi.fn() }}>
        {ui}
      </AuthContext.Provider>
    </MemoryRouter>
  );
}

describe("RouteGuards", () => {
  beforeEach(() => {
    mockNavigate.mockClear();
  });
  afterEach(() => {
    mockNavigate.mockClear();
  });

  it("AdminRoute redirects unauthenticated to /login", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<AdminRoute />}>
          <Route path="/admin" element={<div>ADMIN</div>} />
        </Route>
        <Route path="/login" element={<div>LOGIN</div>} />
      </Routes>,
      { user: null, loading: false },
      "/admin"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/login", expect.objectContaining({ replace: true })));
  });

  it("StudentRoute redirects unauthenticated to /login", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<StudentRoute />}>
          <Route path="/student" element={<div>STUDENT</div>} />
        </Route>
        <Route path="/login" element={<div>LOGIN</div>} />
      </Routes>,
      { user: null, loading: false },
      "/student"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/login", expect.objectContaining({ replace: true })));
  });

  it("TutorRoute redirects unauthenticated to /login", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<TutorRoute />}>
          <Route path="/tutor" element={<div>TUTOR</div>} />
        </Route>
        <Route path="/login" element={<div>LOGIN</div>} />
      </Routes>,
      { user: null, loading: false },
      "/tutor"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/login", expect.objectContaining({ replace: true })));
  });

  it("AdminRoute allows authenticated admin", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<AdminRoute />}>
          <Route path="/admin" element={<div>ADMIN</div>} />
        </Route>
      </Routes>,
      { user: adminUser, loading: false },
      "/admin"
    );
    expect(await screen.findByText("ADMIN")).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("StudentRoute allows authenticated student", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<StudentRoute />}>
          <Route path="/student" element={<div>STUDENT</div>} />
        </Route>
      </Routes>,
      { user: studentUser, loading: false },
      "/student"
    );
    expect(await screen.findByText("STUDENT")).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("TutorRoute allows authenticated tutor", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<TutorRoute />}>
          <Route path="/tutor" element={<div>TUTOR</div>} />
        </Route>
      </Routes>,
      { user: tutorUser, loading: false },
      "/tutor"
    );
    expect(await screen.findByText("TUTOR")).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("AdminRoute does not redirect while loading", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<AdminRoute />}>
          <Route path="/admin" element={<div>ADMIN</div>} />
        </Route>
      </Routes>,
      { user: null, loading: true },
      "/admin"
    );
    expect(screen.getByText(/Verifying administrative authorization/)).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("StudentRoute redirects admin to /admin", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<StudentRoute />}>
          <Route path="/student" element={<div>STUDENT</div>} />
        </Route>
      </Routes>,
      { user: adminUser, loading: false },
      "/student"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/admin", expect.objectContaining({ replace: true })));
  });

  it("TutorRoute redirects admin to /admin", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<TutorRoute />}>
          <Route path="/tutor" element={<div>TUTOR</div>} />
        </Route>
      </Routes>,
      { user: adminUser, loading: false },
      "/tutor"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/admin", expect.objectContaining({ replace: true })));
  });
});
