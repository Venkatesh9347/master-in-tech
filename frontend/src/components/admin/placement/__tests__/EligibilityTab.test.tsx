import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import { render, screen, waitFor, cleanup, fireEvent } from "@testing-library/react";
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

import EligibilityTab from "../EligibilityTab";

function item(id: number, name: string, status: string) {
  return {
    id,
    name,
    email: `${name.toLowerCase()}@example.com`,
    phone: null,
    student_id: `STU-${id}`,
    avatar: null,
    eligibility: {
      student_id: id,
      student_name: name,
      is_eligible: false,
      course_completed: false,
      certificates_count: 0,
      is_admin_override: false,
      reasons: [],
      courses: [],
      batch: null,
      mock_interview_state: "not_scheduled",
      mock_interview: null,
      has_active_booking: false,
      active_booking: null,
      completed_mock: null,
      latest_evaluation: null,
      is_eligible_for_activation: false,
      placement_dashboard_status: status,
      placement_dashboard_enabled: false,
      placement_eligible: false,
    },
  };
}

function envelope(items: unknown[], page = 1, lastPage = 1, total?: number) {
  return {
    data: {
      current_page: page,
      data: items,
      total: total ?? items.length,
      per_page: 15,
      last_page: lastPage,
    },
  };
}

function renderTab() {
  render(
    <MemoryRouter>
      <EligibilityTab />
    </MemoryRouter>
  );
}

describe("EligibilityTab roster pagination", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
  });

  it("renders envelope rows and page info", async () => {
    mocks.apiInstance.get.mockImplementation(() =>
      Promise.resolve(envelope([item(1, "Alice", "DISABLED"), item(2, "Bob", "ENABLED")], 1, 3, 32))
    );

    renderTab();

    await waitFor(() => expect(screen.getByText("Alice")).toBeTruthy());
    expect(screen.getByText("Bob")).toBeTruthy();
    expect(screen.getByText("Page 1 of 3 · 32 students")).toBeTruthy();
    expect(screen.getByRole("button", { name: /Next/ })).toBeTruthy();
  });

  it("navigates to the next page through the bounded API", async () => {
    const seen: string[] = [];
    mocks.apiInstance.get.mockImplementation((url: string) => {
      seen.push(url);
      if (url.includes("page=2")) {
        return Promise.resolve(envelope([item(3, "Cara", "DISABLED")], 2, 2, 16));
      }
      return Promise.resolve(envelope([item(1, "Alice", "DISABLED")], 1, 2, 16));
    });

    renderTab();

    await waitFor(() => expect(screen.getByText("Alice")).toBeTruthy());
    fireEvent.click(screen.getByRole("button", { name: /Next/ }));

    await waitFor(() => expect(screen.getByText("Cara")).toBeTruthy());
    expect(seen.some((u) => u.includes("page=2"))).toBe(true);
    expect(screen.getByText("Page 2 of 2 · 16 students")).toBeTruthy();
  });

  it("still renders legacy plain-array responses without a pager", async () => {
    mocks.apiInstance.get.mockImplementation(() => Promise.resolve({ data: [item(1, "Alice", "DISABLED")] }));

    renderTab();

    await waitFor(() => expect(screen.getByText("Alice")).toBeTruthy());
    expect(screen.queryByRole("button", { name: /Next/ })).toBeNull();
  });

  it("shows an error when the roster request fails", async () => {
    mocks.apiInstance.get.mockImplementation(() => Promise.reject(new Error("network")));

    renderTab();

    await waitFor(() =>
      expect(screen.getByText("Failed to load students placement eligibility list.")).toBeTruthy()
    );
  });
});
