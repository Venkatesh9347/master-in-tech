import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
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

vi.mock("../../components/Navbar", () => ({ default: () => <div>NAVBAR</div> }));
vi.mock("../../components/Footer", () => ({ default: () => <div>FOOTER</div> }));
vi.mock("../../components/courses/CourseCard", () => ({
  default: ({ course }: { course: { title: string } }) => (
    <div data-testid="course-card">{course.title}</div>
  ),
}));
vi.mock("../../components/PublicAccessGateModal", () => ({ default: () => null }));

import Courses from "../Courses";

/** Minimal catalog course shape used by the fixtures. */
type CourseFixture = {
  id: number;
  title: string;
  slug: string;
  description: string;
  category: string;
  instructor: string;
  duration: string;
  difficulty: string;
  is_published: boolean;
};

/** Build a Laravel paginator envelope. */
function envelope<T>(
  items: T[],
  opts: { total?: number; perPage?: number; page?: number } = {},
) {
  const perPage = opts.perPage ?? 12;
  const page = opts.page ?? 1;
  const total = opts.total ?? items.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  // Laravel reports `from`/`to` as 1-based offsets into the filtered result set.
  const from = items.length ? (page - 1) * perPage + 1 : null;
  return {
    data: items,
    current_page: page,
    last_page: lastPage,
    per_page: perPage,
    total,
    from,
    to: items.length ? (from as number) + items.length - 1 : null,
  };
}

const mk = (n: number): CourseFixture[] =>
  Array.from({ length: n }, (_, i) => ({
    id: i + 1,
    title: `Course ${String(i + 1).padStart(2, "0")}`,
    slug: `course-${i + 1}`,
    description: "d",
    category: "AI & ML",
    instructor: "I",
    duration: "6 weeks",
    difficulty: "Basic",
    is_published: true,
  }));

/** Answers paginated requests from a full in-memory result set. */
function servePaginated(total: number, perPage = 12) {
  mocks.apiInstance.get.mockImplementation((...args: unknown[]) => {
    const url = String(args[0] ?? "");
    if (url.startsWith("/public/courses")) {
      const q = new URLSearchParams(url.split("?")[1] ?? "");
      const page = Number(q.get("page") ?? "1");
      const size = Number(q.get("per_page") ?? String(perPage));
      const all = mk(total);
      const slice = all.slice((page - 1) * size, page * size);
      return Promise.resolve({ data: envelope(slice, { total, perPage: size, page }) });
    }
    if (url === "/course-categories") return Promise.resolve({ data: [] });
    return Promise.resolve({ data: [] });
  });
}

const catalogRequests = () =>
  mocks.apiInstance.get.mock.calls
    .map((c) => String(c[0]))
    .filter((u) => u.startsWith("/public/courses"));

/** Every request the page issues must carry a real URL string. */
const allRequests = () => mocks.apiInstance.get.mock.calls.map((c) => c[0]);

/**
 * The catalog's own count line ("Showing 1-12 of 30 courses").
 *
 * The shared Pagination component renders a second status line worded
 * "... of 30 results. Page 1 of 3.", so both a tag-name and a wording filter are
 * needed to select the catalog line unambiguously. The count is split across
 * nested <span>s, hence a function matcher over the element's textContent.
 */
const catalogCountLine = () => {
  const nodes = screen.queryAllByText((_content, el) => {
    if (el?.tagName !== "P") return false;
    return /of\s*\d+\s*courses/i.test(el.textContent ?? "");
  });
  return (nodes[0]?.textContent ?? "").replace(/\s+/g, " ").trim();
};

describe("Courses page pagination (B16)", () => {
  afterEach(() => cleanup());
  beforeEach(() => mocks.apiInstance.get.mockReset());

  it("requests page 1 with the configured page size and renders those rows", async () => {
    servePaginated(30);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getAllByTestId("course-card").length).toBeGreaterThan(0));
    expect(screen.getAllByTestId("course-card")).toHaveLength(12);

    const first = catalogRequests()[0];
    expect(first).toContain("page=1");
    expect(first).toContain("per_page=12");
  });

  it("issues only the public catalog and category requests", async () => {
    servePaginated(30);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getAllByTestId("course-card")).toHaveLength(12));

    const urls = allRequests();
    expect(urls.length).toBeGreaterThan(0);
    for (const u of urls) expect(typeof u).toBe("string");
    // The complete-catalog endpoint must not be used by the public catalog.
    expect(urls.some((u) => String(u).startsWith("/courses?"))).toBe(false);
    expect(urls.filter((u) => String(u).startsWith("/public/courses"))).toHaveLength(1);
  });

  it("does not download the whole catalog in one request", async () => {
    servePaginated(107);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getAllByTestId("course-card")).toHaveLength(12));
    expect(screen.getAllByTestId("course-card")).toHaveLength(12);
    // The server told us 107 exist; the DOM holds only this page.
    expect(catalogCountLine()).toContain("107");
  });

  it("reports the authoritative total, not just the visible page", async () => {
    servePaginated(30);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(catalogCountLine()).not.toBe(""));
    expect(catalogCountLine()).toContain("1\u201312");
    expect(catalogCountLine()).toContain("of 30 courses");
  });

  it("renders pagination controls and requests page 2 when selected", async () => {
    servePaginated(30);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getAllByTestId("course-card")).toHaveLength(12));

    fireEvent.click(screen.getByRole("button", { name: "Go to page 2" }));

    await waitFor(() => {
      expect(catalogRequests().some((u) => u.includes("page=2"))).toBe(true);
    });
    await waitFor(() =>
      expect(screen.getByText("Course 13")).toBeTruthy(),
    );
    expect(screen.queryByText("Course 01")).toBeNull();
  });

  it("renders no duplicate course cards on page 2", async () => {
    servePaginated(30);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getAllByTestId("course-card")).toHaveLength(12));
    fireEvent.click(screen.getByRole("button", { name: "Go to page 2" }));
    await waitFor(() => expect(screen.getByText("Course 13")).toBeTruthy());

    const titles = screen.getAllByTestId("course-card").map((n) => n.textContent);
    expect(titles).toHaveLength(12);
    expect(new Set(titles).size).toBe(titles.length);
  });

  it("shows a single page button when everything fits on one page", async () => {
    servePaginated(5);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getAllByTestId("course-card")).toHaveLength(5));
    expect(screen.getByLabelText("Course catalog pages")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Go to page 1" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Go to page 2" })).toBeNull();
  });

  it("resets to page 1 when the search term changes", async () => {
    servePaginated(60);
    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getAllByTestId("course-card")).toHaveLength(12));
    fireEvent.click(screen.getByRole("button", { name: "Go to page 3" }));
    await waitFor(() => expect(catalogRequests().some((u) => u.includes("page=3"))).toBe(true));

    const before = catalogRequests().length;
    fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: "AI" } });

    await waitFor(() => {
      const added = catalogRequests().slice(before);
      expect(added.length).toBeGreaterThan(0);
      expect(added[added.length - 1]).toContain("page=1");
      expect(added[added.length - 1]).toContain("search=AI");
    });
  });

  it("resets to page 1 when the category filter changes", async () => {
    mocks.apiInstance.get.mockImplementation((...args: unknown[]) => {
    const url = String(args[0] ?? "");
      if (url.startsWith("/public/courses")) {
        const q = new URLSearchParams(url.split("?")[1] ?? "");
        const page = Number(q.get("page") ?? "1");
        const items = mk(60).slice((page - 1) * 12, page * 12);
        return Promise.resolve({ data: envelope(items, { total: 60, perPage: 12, page }) });
      }
      if (url === "/course-categories")
        return Promise.resolve({ data: [{ category: "AI & ML", count: 60 }] });
      return Promise.resolve({ data: [] });
    });

    render(<MemoryRouter><Courses /></MemoryRouter>);
    await waitFor(() => expect(screen.getAllByTestId("course-card")).toHaveLength(12));

    fireEvent.click(screen.getByRole("button", { name: "Go to page 3" }));
    await waitFor(() => expect(catalogRequests().some((u) => u.includes("page=3"))).toBe(true));

    const before = catalogRequests().length;
    fireEvent.click(screen.getByRole("button", { name: "AI & ML" }));

    await waitFor(() => {
      const added = catalogRequests().slice(before);
      expect(added.length).toBeGreaterThan(0);
      expect(added[added.length - 1]).toContain("page=1");
      expect(added[added.length - 1]).toContain("category=AI");
    });
  });

  it("still shows the empty state when a filter matches nothing", async () => {
    mocks.apiInstance.get.mockImplementation((...args: unknown[]) => {
    const url = String(args[0] ?? "");
      if (url.startsWith("/public/courses"))
        return Promise.resolve({ data: envelope([], { total: 0, perPage: 12, page: 1 }) });
      if (url === "/course-categories") return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });

    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() =>
      expect(screen.getByText(/No courses match your criteria/)).toBeTruthy(),
    );
    expect(catalogCountLine()).toContain("of 0 courses");
  });

  it("still surfaces the API error state", async () => {
    mocks.apiInstance.get.mockImplementation((...args: unknown[]) => {
    const url = String(args[0] ?? "");
      if (url.startsWith("/public/courses")) return Promise.reject(new Error("network"));
      return Promise.resolve({ data: [] });
    });

    render(<MemoryRouter><Courses /></MemoryRouter>);

    await waitFor(() => expect(screen.getByText(/Failed to load courses/)).toBeTruthy());
  });

  it("recovers when the requested page is beyond the last page", async () => {
    // Server reports last_page = 2 while the hook is asked for page 9.
    mocks.apiInstance.get.mockImplementation((...args: unknown[]) => {
    const url = String(args[0] ?? "");
      if (url.startsWith("/public/courses")) {
        const q = new URLSearchParams(url.split("?")[1] ?? "");
        const page = Number(q.get("page") ?? "1");
        const items = page <= 2 ? mk(12) : [];
        return Promise.resolve({ data: envelope(items, { total: 24, perPage: 12, page }) });
      }
      if (url === "/course-categories") return Promise.resolve({ data: [] });
      return Promise.resolve({ data: [] });
    });

    render(<MemoryRouter initialEntries={["/courses?page=9"]}><Courses /></MemoryRouter>);

    await waitFor(() => expect(catalogRequests().some((u) => u.includes("page=9"))).toBe(true));
    await waitFor(() => expect(catalogRequests().some((u) => u.includes("page=2"))).toBe(true));
  });
});
