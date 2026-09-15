import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
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

vi.mock("../../components/Navbar", () => ({ default: () => <div>NAVBAR</div> }));
vi.mock("../../components/Footer", () => ({ default: () => <div>FOOTER</div> }));
vi.mock("../../components/courses/CourseCard", () => ({
  default: ({ course }: { course: { title: string } }) => <div>{course.title}</div>,
}));
vi.mock("../../components/PublicAccessGateModal", () => ({
  default: () => null,
}));

import Courses from "../Courses";

const courseA = {
  id: 1,
  title: "Alpha Course",
  slug: "alpha",
  description: "Alpha desc",
  category: "AI & ML",
  instructor: "Alice",
  duration: "4 weeks",
  difficulty: "Beginner",
  is_published: true,
} as any;

const courseB = {
  id: 2,
  title: "Beta Course",
  slug: "beta",
  description: "Beta desc",
  category: "Full Stack",
  instructor: "Bob",
  duration: "6 weeks",
  difficulty: "Advanced",
  is_published: true,
} as any;

describe("Courses page", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
  });

  it("renders courses from valid API array response", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/courses") return Promise.resolve({ data: [courseA, courseB] });
      if (url === "/course-categories") return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });

    render(
      <MemoryRouter>
        <Courses />
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.getByText("Alpha Course")).toBeTruthy());
    expect(screen.getByText("Beta Course")).toBeTruthy();
    expect(screen.getByText(/Showing/)).toBeTruthy();
  });

  it("renders empty state when API returns empty array", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/courses") return Promise.resolve({ data: [] });
      if (url === "/course-categories") return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });

    render(
      <MemoryRouter>
        <Courses />
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.getByText(/No courses match your criteria/)).toBeTruthy());
  });

  it("handles wrapped data shape { data: [...] } safely", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/courses") return Promise.resolve({ data: { data: [courseA] } });
      if (url === "/course-categories") return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });

    render(
      <MemoryRouter>
        <Courses />
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.getByText("Alpha Course")).toBeTruthy());
  });

  it("shows error on API failure", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/courses") return Promise.reject(new Error("network"));
      return Promise.resolve({ data: [] });
    });

    render(
      <MemoryRouter>
        <Courses />
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.getByText(/Failed to load courses/)).toBeTruthy());
  });

  it("fails safely on malformed responses (null / string / unexpected object)", async () => {
    for (const malformed of [{ data: null }, { data: "oops" }, { data: { unexpected: true } }, {}]) {
      mocks.apiInstance.get.mockImplementation((url: string) => {
        if (url === "/courses") return Promise.resolve(malformed);
        if (url === "/course-categories") return Promise.resolve({ data: [] });
        return Promise.resolve({ data: [] });
      });

      render(
        <MemoryRouter>
          <Courses />
        </MemoryRouter>
      );

      await waitFor(() => expect(screen.getByText(/No courses match your criteria/)).toBeTruthy());
      cleanup();
    }
  });

  it("tolerates course entries with missing fields", async () => {
    const sparse = { id: 9, title: "Sparse Course" } as any;
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/courses") return Promise.resolve({ data: [sparse, courseA] });
      if (url === "/course-categories") return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });

    render(
      <MemoryRouter>
        <Courses />
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.getByText("Sparse Course")).toBeTruthy());
    expect(screen.getByText("Alpha Course")).toBeTruthy();
  });

  it("does not require Authorization for public course request (exempt)", async () => {
    // The isExemptUrl logic is tested in api.test; here we just ensure the component makes the call
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (url === "/courses") return Promise.resolve({ data: [courseA] });
      if (url === "/course-categories") return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });

    render(
      <MemoryRouter>
        <Courses />
      </MemoryRouter>
    );

    await waitFor(() => expect(mocks.apiInstance.get).toHaveBeenCalledWith("/courses"));
    // Even if localStorage has a stale token, the request should not include it for /courses (verified in api.test)
  });
});
