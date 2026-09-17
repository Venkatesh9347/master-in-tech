import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { renderHook, waitFor, act, cleanup } from "@testing-library/react";
import { usePagedQuery } from "../usePagedQuery";

afterEach(() => {
  cleanup();
});

const mockGet = vi.fn();

vi.mock("../../services/api", () => ({
  default: {
    get: (...args: unknown[]) => mockGet(...args),
  },
}));

interface Row {
  id: number;
}

function pageResponse(ids: number[], page: number, lastPage: number, total: number) {
  return {
    data: {
      data: ids.map((id) => ({ id })),
      current_page: page,
      last_page: lastPage,
      per_page: 15,
      total,
      from: ids.length > 0 ? 1 : null,
      to: ids.length > 0 ? ids.length : null,
    },
  };
}

function requestedUrls(): string[] {
  return mockGet.mock.calls.map((call) => String((call[0] as string) ?? ""));
}

describe("usePagedQuery", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockGet.mockResolvedValue(pageResponse([1, 2], 1, 1, 2));
  });

  it("requests page 1 with per_page and exposes items plus metadata", async () => {
    const { result } = renderHook(() => usePagedQuery<Row>("/admin/users", {}));

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.items).toEqual([{ id: 1 }, { id: 2 }]);
    expect(result.current.meta?.total).toBe(2);
    expect(requestedUrls()).toEqual(["/admin/users?page=1&per_page=15"]);
  });

  it("requests the selected page on page change", async () => {
    const { result } = renderHook(() => usePagedQuery<Row>("/admin/users", {}));

    await waitFor(() => expect(result.current.loading).toBe(false));

    act(() => {
      result.current.setPage(3);
    });

    await waitFor(() =>
      expect(requestedUrls()).toContain("/admin/users?page=3&per_page=15")
    );
  });

  it("resets to page 1 when filters change", async () => {
    const { result, rerender } = renderHook(
      ({ search }: { search: string }) =>
        usePagedQuery<Row>("/admin/users", { search: search || undefined }),
      { initialProps: { search: "" } }
    );

    await waitFor(() => expect(result.current.loading).toBe(false));

    // Move to page 2 first (blank filters are omitted from the query).
    mockGet.mockResolvedValueOnce(pageResponse([3], 2, 2, 3));
    act(() => {
      result.current.setPage(2);
    });
    await waitFor(() =>
      expect(requestedUrls()).toContain("/admin/users?page=2&per_page=15")
    );

    // Changing the filter must request page 1 (not page 2).
    mockGet.mockClear();
    mockGet.mockResolvedValue(pageResponse([9], 1, 1, 1));
    rerender({ search: "anna" });

    await waitFor(() =>
      expect(requestedUrls()).toContain("/admin/users?search=anna&page=1&per_page=15")
    );
    expect(requestedUrls().some((url) => url.includes("page=2"))).toBe(false);
  });

  it("recovers to the last valid page when the requested page is gone", async () => {
    const { result } = renderHook(() => usePagedQuery<Row>("/admin/users", {}));

    await waitFor(() => expect(result.current.loading).toBe(false));

    // Server now reports only 4 pages while we ask for page 5.
    mockGet.mockResolvedValue(pageResponse([], 5, 4, 60));
    act(() => {
      result.current.setPage(5);
    });

    await waitFor(() =>
      expect(requestedUrls()).toContain("/admin/users?page=4&per_page=15")
    );
  });

  it("surfaces empty results and API errors", async () => {
    mockGet.mockResolvedValueOnce(pageResponse([], 1, 1, 0));
    const { result, unmount } = renderHook(() => usePagedQuery<Row>("/admin/users", {}));

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.items).toEqual([]);
    expect(result.current.meta?.total).toBe(0);
    unmount();

    mockGet.mockReset();
    mockGet.mockRejectedValueOnce(new Error("boom"));
    const failed = renderHook(() =>
      usePagedQuery<Row>("/admin/users", {}, { errorMessage: "Custom failure." })
    );

    await waitFor(() => expect(failed.result.current.loading).toBe(false));
    expect(failed.result.current.error).toBe("Custom failure.");
    expect(failed.result.current.items).toEqual([]);
  });
});
