import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, cleanup } from "@testing-library/react";
import { MemoryRouter, Routes, Route } from "react-router-dom";

const mocks = vi.hoisted(() => {
  const apiInstance = {
    interceptors: { request: { use: () => {} }, response: { use: () => {} } },
    get: vi.fn(),
    post: vi.fn(),
  };
  return { apiInstance };
});

vi.mock("axios", () => ({
  default: { create: () => mocks.apiInstance },
}));

import TutorCourseAnalytics from "../tutor/TutorCourseAnalytics";

const baseData = {
  course: { id: 7, title: "Analytics Course" },
  total_enrolled: 10,
  in_progress: 4,
  completed: 6,
  average_progress: 72,
  reviews_count: 0,
  reviews: [],
  submissions_count: 3,
  graded_submissions_count: 2,
  quiz_attempts_count: 5,
  average_quiz_score: 80,
} as any;

function renderAnalytics() {
  return render(
    <MemoryRouter initialEntries={["/tutor/courses/7/analytics"]}>
      <Routes>
        <Route path="/tutor/courses/:id/analytics" element={<TutorCourseAnalytics />} />
      </Routes>
    </MemoryRouter>
  );
}

describe("TutorCourseAnalytics rating display", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
  });

  it("shows an explicit neutral state when average_rating is null", async () => {
    mocks.apiInstance.get.mockImplementation(() =>
      Promise.resolve({ data: { ...baseData, average_rating: null } })
    );

    renderAnalytics();

    // Both the metric card and the feedback header show the neutral state.
    await waitFor(() => expect(screen.getAllByText("No ratings yet")).toHaveLength(2));
    expect(
      screen.queryByText((_, el) => el?.textContent === "★ 5 / 5.0")
    ).toBeNull();
  });

  it("renders the calculated average when reviews exist", async () => {
    mocks.apiInstance.get.mockImplementation(() =>
      Promise.resolve({
        data: {
          ...baseData,
          average_rating: 4.5,
          reviews_count: 2,
          reviews: [{ id: 1, rating: 5, review_text: "Great", created_at: "", user: { name: "Sam" } }],
        },
      })
    );

    renderAnalytics();

    await waitFor(() => expect(screen.getByText("★ 4.5")).toBeTruthy());
    expect(screen.getByText("★ 4.5 / 5.0")).toBeTruthy();
    expect(screen.queryByText("No ratings yet")).toBeNull();
  });
});
