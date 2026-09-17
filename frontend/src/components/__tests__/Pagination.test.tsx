import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen, fireEvent, cleanup } from "@testing-library/react";
import Pagination from "../Pagination";
import type { PageMeta } from "../../types/pagination";

afterEach(() => {
  cleanup();
});

function meta(overrides: Partial<PageMeta> = {}): PageMeta {
  return {
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    from: null,
    to: null,
    ...overrides,
  };
}

function button(name: string): HTMLButtonElement {
  const element = screen.getByRole("button", { name });
  if (!(element instanceof HTMLButtonElement)) {
    throw new Error(`Expected a button for ${name}`);
  }
  return element;
}

describe("Pagination", () => {
  it("renders nothing when there are no results", () => {
    const { container } = render(
      <Pagination meta={meta({ total: 0, last_page: 1 })} onPageChange={() => {}} />
    );
    expect(container.firstChild).toBeNull();
  });

  it("disables previous on the first page and marks current page", () => {
    const onPageChange = vi.fn();
    render(
      <Pagination
        meta={meta({ current_page: 1, last_page: 5, total: 70, from: 1, to: 15 })}
        onPageChange={onPageChange}
      />
    );

    expect(button("Go to previous page").disabled).toBe(true);
    expect(button("Go to next page").disabled).toBe(false);
    expect(button("Go to page 1").getAttribute("aria-current")).toBe("page");

    fireEvent.click(button("Go to page 3"));
    expect(onPageChange).toHaveBeenCalledWith(3);
  });

  it("shows a window with boundaries on a middle page", () => {
    render(
      <Pagination
        meta={meta({ current_page: 5, last_page: 10, total: 150, from: 61, to: 75 })}
        onPageChange={() => {}}
      />
    );

    // First + last boundaries plus neighbors of page 5.
    for (const label of ["Go to page 1", "Go to page 4", "Go to page 5", "Go to page 6", "Go to page 10"]) {
      expect(button(label)).toBeTruthy();
    }
    expect(button("Go to page 5").getAttribute("aria-current")).toBe("page");
  });

  it("disables next on the last page", () => {
    const onPageChange = vi.fn();
    render(
      <Pagination
        meta={meta({ current_page: 4, last_page: 4, total: 60, from: 46, to: 60 })}
        onPageChange={onPageChange}
      />
    );

    expect(button("Go to next page").disabled).toBe(true);
    expect(button("Go to previous page").disabled).toBe(false);

    fireEvent.click(button("Go to previous page"));
    expect(onPageChange).toHaveBeenCalledWith(3);
  });

  it("exposes an accessible landmark, label, and live status", () => {
    render(
      <Pagination
        meta={meta({ current_page: 2, last_page: 4, total: 60, from: 16, to: 30 })}
        onPageChange={() => {}}
        label="Leads roster pagination"
      />
    );

    expect(screen.getByRole("navigation", { name: "Leads roster pagination" })).toBeTruthy();
    expect(screen.getByText("Showing 16–30 of 60 results. Page 2 of 4.")).toBeTruthy();
  });
});
