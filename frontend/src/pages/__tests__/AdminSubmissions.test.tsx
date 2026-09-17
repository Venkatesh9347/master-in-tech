import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import { render, screen, waitFor, cleanup } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

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

import AdminSubmissions from "../AdminSubmissions";

const subA = {
  id: 1,
  user_id: 11,
  assignment_id: 21,
  course_id: 31,
  submission_text: "solution A",
  file_url: null,
  submitted_at: null,
  score: null,
  feedback: null,
  status: "submitted",
  user: { id: 11, name: "Alice Learner", email: "alice@example.com" },
  assignment: { id: 21, title: "Capstone Project", max_marks: 100 },
  course: { id: 31, title: "Full Stack" },
};

const subB = {
  ...subA,
  id: 2,
  user_id: 12,
  submission_text: "solution B",
  status: "graded",
  score: 92,
  user: { id: 12, name: "Bob Learner", email: "bob@example.com" },
};

function renderDesk() {
  render(
    <MemoryRouter>
      <AdminSubmissions />
    </MemoryRouter>
  );
}

describe("AdminSubmissions page", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
  });

  it("renders rows from the paginated envelope { data: [...] }", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/admin/assignments/submissions") {
        return Promise.resolve({
          data: {
            current_page: 1,
            data: [subA, subB],
            per_page: 15,
            total: 2,
          },
        });
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("Alice Learner")).toBeTruthy());
    expect(screen.getByText("Bob Learner")).toBeTruthy();
    expect(screen.getAllByText("Capstone Project")).toHaveLength(2);
  });

  it("still renders legacy plain-array responses", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/admin/assignments/submissions") {
        return Promise.resolve({ data: [subA] });
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("Alice Learner")).toBeTruthy());
  });

  it("shows the empty state for empty or malformed payloads", async () => {
    for (const payload of [{ data: [] }, { data: { data: [] } }, { data: null }, {}]) {
      mocks.apiInstance.get.mockImplementation(() => Promise.resolve(payload));

      renderDesk();

      await waitFor(() =>
        expect(screen.getByText("No submissions found for the selected filter.")).toBeTruthy()
      );
      cleanup();
    }
  });

  it("shows an error when the request fails", async () => {
    mocks.apiInstance.get.mockImplementation(() => Promise.reject(new Error("network")));

    renderDesk();

    await waitFor(() =>
      expect(screen.getByText("Failed to load assignment submissions.")).toBeTruthy()
    );
  });
});
