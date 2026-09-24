import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, cleanup, fireEvent } from "@testing-library/react";
import { MemoryRouter, Routes, Route, useLocation } from "react-router-dom";

const mocks = vi.hoisted(() => {
  const apiInstance = {
    interceptors: { request: { use: () => {} }, response: { use: () => {} } },
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  };
  return { apiInstance };
});

vi.mock("axios", () => ({
  default: { create: () => mocks.apiInstance },
}));

vi.mock("../../components/lms/VideoPlayer", () => ({ default: () => <div>VIDEO</div> }));
vi.mock("../../components/lms/TextReader", () => ({ default: () => <div>TEXT</div> }));
vi.mock("../../components/lms/LessonResourcesBox", () => ({ default: () => <div>RESOURCES</div> }));
vi.mock("../../components/lms/QuizPlayer", () => ({ default: () => <div>QUIZ</div> }));
vi.mock("../../components/lms/AssignmentViewer", () => ({ default: () => <div>ASSIGNMENT</div> }));
vi.mock("../../components/EnquiryModal", () => ({ default: () => null }));
vi.mock("../../components/live/LiveClassList", () => ({ default: () => null }));

import StudentLessons from "../StudentLessons";

function LocationProbe() {
  const loc = useLocation();
  return <div data-testid="location">{loc.pathname}</div>;
}

function renderLessons() {
  return render(
    <MemoryRouter initialEntries={["/student/courses/5/lessons"]}>
      <Routes>
        <Route path="/student/courses/:courseId/lessons" element={<StudentLessons />} />
        <Route path="*" element={<LocationProbe />} />
      </Routes>
    </MemoryRouter>
  );
}

const progress = {
  progress_percentage: 100,
  completed_count: 2,
  total_lessons: 2,
  is_course_completed: true,
  completed_lessons: [11, 12],
  current_lesson: null,
  sections: [
    {
      id: 1,
      title: "Section 1",
      lessons: [
        { id: 11, title: "Lesson 1", type: "text" },
        { id: 12, title: "Lesson 2", type: "text" },
      ],
    },
  ],
} as any;

function mockMount(certImpl: (url: string, payload?: unknown) => Promise<unknown>) {
  mocks.apiInstance.get.mockImplementation((url: string) => {
    if (url === "/courses/5/lms-progress") return Promise.resolve({ data: progress });
    if (url === "/courses/5/lessons/11/note") return Promise.resolve({ data: { note: "" } });
    if (url === "/courses/5/lessons/11/discussions") return Promise.resolve({ data: [] });
    return Promise.resolve({ data: [] });
  });
  mocks.apiInstance.post.mockImplementation(certImpl as (url: string) => Promise<unknown>);
}

describe("StudentLessons certificate flow", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
    mocks.apiInstance.post.mockReset();
    mocks.apiInstance.put.mockReset();
  });

  it("navigates to the server-issued certificate code on success", async () => {
    mockMount((url: string) => {
      if (url === "/courses/5/certificate") {
        return Promise.resolve({ data: { certificate: { certificate_code: "MIT-2026-ABCDEF123456" } } });
      }
      return Promise.resolve({ data: {} });
    });

    renderLessons();

    await waitFor(() => expect(screen.getByText("View Official Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("View Official Certificate"));

    await waitFor(() =>
      expect(mocks.apiInstance.post).toHaveBeenCalledWith("/courses/5/certificate")
    );
    await waitFor(() =>
      expect(screen.getByTestId("location").textContent).toBe(
        "/student/certificates/MIT-2026-ABCDEF123456"
      )
    );
  });

  it("shows the backend error and never fabricates a certificate URL on failure", async () => {
    mockMount((url: string) => {
      if (url === "/courses/5/certificate") {
        return Promise.reject({
          response: { data: { message: "Complete all lessons before claiming the certificate." } },
        });
      }
      return Promise.resolve({ data: {} });
    });

    renderLessons();

    await waitFor(() => expect(screen.getByText("View Official Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("View Official Certificate"));

    await waitFor(() =>
      expect(screen.getByText(/complete all lessons before claiming/i)).toBeTruthy()
    );
    // Still on the classroom page — no navigation occurred, so the routed
    // location probe never mounted and no invented certificate URL exists.
    expect(screen.queryByTestId("location")).toBeNull();
    expect(screen.getByText("View Official Certificate")).toBeTruthy();
  });

  it("shows an error instead of navigating when the response carries no code", async () => {
    mockMount((url: string) => {
      if (url === "/courses/5/certificate") return Promise.resolve({ data: {} });
      return Promise.resolve({ data: {} });
    });

    renderLessons();

    await waitFor(() => expect(screen.getByText("View Official Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("View Official Certificate"));

    await waitFor(() =>
      expect(screen.getByText(/certificate is not available yet/i)).toBeTruthy()
    );
    expect(screen.queryByTestId("location")).toBeNull();
    expect(screen.getByText("View Official Certificate")).toBeTruthy();
  });
});
