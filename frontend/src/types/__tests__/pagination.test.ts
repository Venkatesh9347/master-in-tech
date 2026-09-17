import { describe, expect, it } from "vitest";
import { extractPage } from "../pagination";

describe("extractPage", () => {
  it("passes through legacy raw arrays with no metadata", () => {
    const result = extractPage([{ id: 1 }, { id: 2 }]);

    expect(result.items).toEqual([{ id: 1 }, { id: 2 }]);
    expect(result.meta).toBeNull();
  });

  it("retains metadata from a flat Laravel paginator envelope", () => {
    const result = extractPage({
      data: [{ id: 7 }],
      current_page: 2,
      last_page: 4,
      per_page: 15,
      total: 60,
      from: 16,
      to: 30,
    });

    expect(result.items).toEqual([{ id: 7 }]);
    expect(result.meta).toEqual({
      current_page: 2,
      last_page: 4,
      per_page: 15,
      total: 60,
      from: 16,
      to: 30,
    });
  });

  it("returns items without metadata when the envelope has no page keys", () => {
    const result = extractPage({ data: [{ id: 1 }] });

    expect(result.items).toEqual([{ id: 1 }]);
    expect(result.meta).toBeNull();
  });

  it("returns an empty list for unknown shapes", () => {
    expect(extractPage(null)).toEqual({ items: [], meta: null });
    expect(extractPage(undefined)).toEqual({ items: [], meta: null });
    expect(extractPage({ nonsense: true })).toEqual({ items: [], meta: null });
    expect(extractPage("oops")).toEqual({ items: [], meta: null });
  });
});
