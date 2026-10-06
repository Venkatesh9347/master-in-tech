import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, cleanup } from "@testing-library/react";
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
import CounsellorRoute from "../CounsellorRoute";
import StudentRoute from "../StudentRoute";
import TutorRoute from "../TutorRoute";
import CompanyRoute from "../CompanyRoute";
import PlacementRoute from "../PlacementRoute";
import { AuthContext } from "../../context/auth-context";
import type { User } from "../../context/auth-context";

const adminUser: User = { id: 1, name: "Admin", email: "admin@example.com", role: "admin" };
const tutorUser: User = { id: 2, name: "Tutor", email: "tutor@example.com", role: "tutor" };
const studentUser: User = { id: 3, name: "Student", email: "student@example.com", role: "student" };
const telecallerUser: User = { id: 4, name: "Tele", email: "tele@example.com", role: "telecaller" };
const placementAdvisorUser: User = { id: 5, name: "Placement", email: "placement@example.com", role: "placement_advisor" };
const companyUser: User = { id: 6, name: "Partner", email: "partner@example.com", role: "company" };

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
    // This project runs vitest with `globals: false`, so Testing Library's
    // automatic cleanup is never registered. Without this the DOM leaks
    // between tests and absence assertions (queryByText(...).toBeNull())
    // match nodes rendered by an earlier case.
    cleanup();
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

  it("CounsellorRoute allows telecaller frontline staff", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<CounsellorRoute />}>
          <Route path="/admin/crm" element={<div>CRM</div>} />
        </Route>
      </Routes>,
      { user: telecallerUser, loading: false },
      "/admin/crm"
    );
    expect(await screen.findByText("CRM")).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("CounsellorRoute redirects student to /student", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<CounsellorRoute />}>
          <Route path="/admin/crm" element={<div>CRM</div>} />
        </Route>
      </Routes>,
      { user: studentUser, loading: false },
      "/admin/crm"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/student", expect.objectContaining({ replace: true })));
  });

  // Phases 6/7/8 moved each frontline CRM role onto its own desk. This guard now
  // sends them there rather than to the shared /admin/crm page. Still a redirect
  // away from /admin — the authorization outcome is unchanged.
  it("AdminRoute redirects telecaller to their own desk, never to /admin", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<AdminRoute />}>
          <Route path="/admin" element={<div>ADMIN</div>} />
        </Route>
      </Routes>,
      { user: telecallerUser, loading: false },
      "/admin"
    );
    await waitFor(() =>
      expect(mockNavigate).toHaveBeenCalledWith("/crm/telecaller", expect.objectContaining({ replace: true }))
    );
    expect(mockNavigate).not.toHaveBeenCalledWith("/admin", expect.anything());
  });

  // -------------------------------------------------------------------
  // placement_advisor: the Phase 0 redirect loop.
  //
  // Before this role existed, Login sent it to /student, StudentRoute
  // rejected it and sent it to /login, and the two ping-ponged forever.
  // These assertions pin the fix in place.
  // -------------------------------------------------------------------

  it("PlacementRoute allows a placement_advisor and never redirects", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<PlacementRoute />}>
          <Route path="/placement" element={<div>PLACEMENT</div>} />
        </Route>
      </Routes>,
      { user: placementAdvisorUser, loading: false },
      "/placement"
    );
    expect(await screen.findByText("PLACEMENT")).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("StudentRoute sends a placement_advisor to /placement, not /login", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<StudentRoute />}>
          <Route path="/student" element={<div>STUDENT</div>} />
        </Route>
      </Routes>,
      { user: placementAdvisorUser, loading: false },
      "/student"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/placement", expect.objectContaining({ replace: true })));
    expect(mockNavigate).not.toHaveBeenCalledWith("/login", expect.anything());
  });

  it("AdminRoute sends a placement_advisor to /placement", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<AdminRoute />}>
          <Route path="/admin" element={<div>ADMIN</div>} />
        </Route>
      </Routes>,
      { user: placementAdvisorUser, loading: false },
      "/admin"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/placement", expect.objectContaining({ replace: true })));
    expect(screen.queryByText("ADMIN")).toBeNull();
  });

  it("TutorRoute sends a placement_advisor to /placement", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<TutorRoute />}>
          <Route path="/tutor" element={<div>TUTOR</div>} />
        </Route>
      </Routes>,
      { user: placementAdvisorUser, loading: false },
      "/tutor"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/placement", expect.objectContaining({ replace: true })));
  });

  it("CompanyRoute sends a placement_advisor to /placement", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<CompanyRoute />}>
          <Route path="/company" element={<div>COMPANY</div>} />
        </Route>
      </Routes>,
      { user: placementAdvisorUser, loading: false },
      "/company"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/placement", expect.objectContaining({ replace: true })));
  });

  it("CounsellorRoute sends a placement_advisor to /placement, not CRM", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<CounsellorRoute />}>
          <Route path="/admin/crm" element={<div>CRM</div>} />
        </Route>
      </Routes>,
      { user: placementAdvisorUser, loading: false },
      "/admin/crm"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/placement", expect.objectContaining({ replace: true })));
    expect(screen.queryByText("CRM")).toBeNull();
  });

  it("PlacementRoute rejects a student and sends them to /student", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<PlacementRoute />}>
          <Route path="/placement" element={<div>PLACEMENT</div>} />
        </Route>
      </Routes>,
      { user: studentUser, loading: false },
      "/placement"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/student", expect.objectContaining({ replace: true })));
    expect(screen.queryByText("PLACEMENT")).toBeNull();
  });

  it("PlacementRoute sends a company user to /company", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<PlacementRoute />}>
          <Route path="/placement" element={<div>PLACEMENT</div>} />
        </Route>
      </Routes>,
      { user: companyUser, loading: false },
      "/placement"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/company", expect.objectContaining({ replace: true })));
  });

  it("PlacementRoute redirects unauthenticated to /login", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<PlacementRoute />}>
          <Route path="/placement" element={<div>PLACEMENT</div>} />
        </Route>
      </Routes>,
      { user: null, loading: false },
      "/placement"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/login", expect.objectContaining({ replace: true })));
  });

  it("PlacementRoute does not redirect while loading", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<PlacementRoute />}>
          <Route path="/placement" element={<div>PLACEMENT</div>} />
        </Route>
      </Routes>,
      { user: null, loading: true },
      "/placement"
    );
    expect(screen.getByText(/Verifying placement authorization/)).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("PlacementRoute sends an admin to /admin (advisors gain no admin access)", async () => {
    renderWithAuth(
      <Routes>
        <Route element={<PlacementRoute />}>
          <Route path="/placement" element={<div>PLACEMENT</div>} />
        </Route>
      </Routes>,
      { user: adminUser, loading: false },
      "/placement"
    );
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith("/admin", expect.objectContaining({ replace: true })));
  });
});
