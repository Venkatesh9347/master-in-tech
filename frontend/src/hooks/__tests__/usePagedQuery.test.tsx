import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { renderHook, waitFor, act, cleanup } from "@testing-library/react";
import { StrictMode } from "react";
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

  // Regression: the served-combination dedupe used to be recorded when a
  // request was DISPATCHED. Under React StrictMode the effect is remounted
  // immediately, its cleanup cancels the in-flight request, and the remount
  // then early-returned on the "already served" check - so no fetch ever
  // completed and `loading` stayed true forever.
  it("settles instead of hanging when StrictMode remounts the effect", async () => {
    mockGet.mockResolvedValue(pageResponse([1, 2, 3], 1, 9, 107));

    const { result } = renderHook(
      () => usePagedQuery<Row>("/public/courses", {}),
      { wrapper: ({ children }) => <StrictMode>{children}</StrictMode> }
    );

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.items).toEqual([{ id: 1 }, { id: 2 }, { id: 3 }]);
    expect(result.current.meta?.total).toBe(107);
    expect(result.current.error).toBeNull();
  });

  it("still issues exactly one request per key/page combination", async () => {
    mockGet.mockResolvedValue(pageResponse([1, 2], 1, 3, 6));
    const { result, rerender } = renderHook(
      ({ search }: { search: string }) =>
        usePagedQuery<Row>("/admin/users", { search: search || undefined }),
      { initialProps: { search: "" } }
    );
    await waitFor(() => expect(result.current.loading).toBe(false));
    // Unrelated re-renders must not re-fetch the same combination.
    mockGet.mockClear();
    rerender({ search: "" });
    rerender({ search: "" });
    expect(requestedUrls()).toEqual([]);

    act(() => {
      result.current.setPage(2);
    });
    await waitFor(() => expect(requestedUrls()).toContain("/admin/users?page=2&per_page=15"));
    expect(requestedUrls().filter((u) => u.includes("page=2"))).toHaveLength(1);
  });

  // ---- StrictMode invariants that the dispatch-time dedupe used to break ----

  it("settles into the error state when StrictMode remounts a failing request", async () => {
    // The `.finally` that clears loading is itself guarded by `cancelled`, so a
    // rejected request must still settle under StrictMode or the consumer hangs.
    mockGet.mockRejectedValue(new Error("boom"));

    const { result } = renderHook(
      () => usePagedQuery<Row>("/admin/users", {}, { errorMessage: "Custom failure." }),
      { wrapper: ({ children }) => <StrictMode>{children}</StrictMode> }
    );

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.error).toBe("Custom failure.");
    expect(result.current.items).toEqual([]);
  });

  it("issues the correct request when filters change under StrictMode", async () => {
    mockGet.mockResolvedValue(pageResponse([9], 1, 1, 1));

    const { result, rerender } = renderHook(
      ({ search }: { search: string }) =>
        usePagedQuery<Row>("/admin/users", { search: search || undefined }),
      { wrapper: ({ children }) => <StrictMode>{children}</StrictMode>, initialProps: { search: "" } }
    );

    await waitFor(() => expect(result.current.loading).toBe(false));
    mockGet.mockClear();

    rerender({ search: "anna" });

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(requestedUrls()).toContain("/admin/users?search=anna&page=1&per_page=15");
    expect(result.current.items).toEqual([{ id: 9 }]);
  });

  it("issues the correct request when the page changes under StrictMode", async () => {
    mockGet.mockResolvedValue(pageResponse([1, 2], 1, 5, 10));

    const { result } = renderHook(
      () => usePagedQuery<Row>("/admin/users", {}),
      { wrapper: ({ children }) => <StrictMode>{children}</StrictMode> }
    );

    await waitFor(() => expect(result.current.loading).toBe(false));
    mockGet.mockClear();

    act(() => {
      result.current.setPage(4);
    });

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(requestedUrls()).toContain("/admin/users?page=4&per_page=15");
    expect(result.current.page).toBe(4);
  });

  // ---- dedupe must never swallow a legitimate request ----

  it("honours reload() even though key and page are unchanged", async () => {
    mockGet.mockResolvedValue(pageResponse([1], 1, 1, 1));

    const { result } = renderHook(() => usePagedQuery<Row>("/admin/users", {}));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(requestedUrls()).toHaveLength(1);

    mockGet.mockClear();
    mockGet.mockResolvedValue(pageResponse([7], 1, 1, 1));
    act(() => {
      result.current.reload();
    });

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(requestedUrls()).toEqual(["/admin/users?page=1&per_page=15"]);
    expect(result.current.items).toEqual([{ id: 7 }]);
  });

  it("recovers from an out-of-range page under StrictMode", async () => {
    mockGet.mockResolvedValue(pageResponse([], 5, 4, 60));

    const { result } = renderHook(
      () => usePagedQuery<Row>("/admin/users", {}),
      { wrapper: ({ children }) => <StrictMode>{children}</StrictMode> }
    );

    await waitFor(() => expect(result.current.loading).toBe(false));
    act(() => {
      result.current.setPage(5);
    });

    await waitFor(() => expect(result.current.page).toBe(4));
    expect(requestedUrls()).toContain("/admin/users?page=4&per_page=15");
  });

  // ---- stale-response ordering guard (documented in the hook) ----

  it("ignores a slow earlier response that resolves after a newer one", async () => {
    // Page 1 settles normally first.
    mockGet.mockResolvedValueOnce(pageResponse([1], 1, 3, 6));
    const { result } = renderHook(() => usePagedQuery<Row>("/admin/users", {}));
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.items).toEqual([{ id: 1 }]);

    // The page-2 request is issued but stalls...
    let resolveSlow: (v: unknown) => void = () => {};
    mockGet.mockReturnValueOnce(
      new Promise((res) => {
        resolveSlow = res;
      })
    );
    act(() => {
      result.current.setPage(2);
    });
    await waitFor(() => expect(result.current.loading).toBe(true));

    // ...while a newer page-3 request completes.
    mockGet.mockResolvedValueOnce(pageResponse([3], 3, 3, 6));
    act(() => {
      result.current.setPage(3);
    });
    await waitFor(() => expect(result.current.items).toEqual([{ id: 3 }]));

    // The stale page-2 payload lands last and must not overwrite page 3.
    await act(async () => {
      resolveSlow(pageResponse([2], 2, 3, 6));
    });

    expect(result.current.items).toEqual([{ id: 3 }]);
    expect(result.current.page).toBe(3);
  });

  // ---- documented contract: items survive a filter change while loading ----

  it("keeps prior rows visible only while loading is true", async () => {
    mockGet.mockResolvedValue(pageResponse([1, 2], 1, 3, 6));

    const { result, rerender } = renderHook(
      ({ search }: { search: string }) =>
        usePagedQuery<Row>("/admin/users", { search: search || undefined }),
      { initialProps: { search: "" } }
    );
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.items).toHaveLength(2);

    let release: (v: unknown) => void = () => {};
    mockGet.mockReturnValueOnce(
      new Promise((res) => {
        release = res;
      })
    );

    rerender({ search: "anna" });

    // Consumers gate on `loading`, so the retained rows must never be paired
    // with loading === false.
    await waitFor(() => expect(result.current.loading).toBe(true));

    await act(async () => {
      release(pageResponse([9], 1, 1, 1));
    });

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.items).toEqual([{ id: 9 }]);
  });
});
