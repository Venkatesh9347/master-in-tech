import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, cleanup } from "@testing-library/react";
import AppErrorBoundary from "../AppErrorBoundary";

/** Throws during render - the exact failure mode the boundary must catch. */
function Boom({ shouldThrow }: { shouldThrow: boolean }) {
  if (shouldThrow) {
    throw new Error("kaboom: intentional render failure");
  }
  return <p>child content</p>;
}

describe("AppErrorBoundary", () => {
  let consoleErrorSpy: ReturnType<typeof vi.spyOn>;
  let originalConsoleError: typeof console.error;

  beforeEach(() => {
    // React logs caught render errors to console.error by design. Swallow
    // only that expected output and restore the real console afterwards so no
    // other assertion in this file is affected.
    originalConsoleError = console.error;
    consoleErrorSpy = vi.spyOn(console, "error").mockImplementation(() => {});
  });

  afterEach(() => {
    consoleErrorSpy.mockRestore();
    console.error = originalConsoleError;
    cleanup();
  });

  it("renders children when no error is thrown", () => {
    render(
      <AppErrorBoundary>
        <Boom shouldThrow={false} />
      </AppErrorBoundary>,
    );

    expect(screen.getByText("child content")).toBeTruthy();
    expect(screen.queryByTestId("app-error-boundary")).toBeNull();
  });

  it("catches a render error and shows the fallback instead of a blank page", () => {
    render(
      <AppErrorBoundary>
        <Boom shouldThrow />
      </AppErrorBoundary>,
    );

    const fallback = screen.getByTestId("app-error-boundary");
    expect(fallback).toBeTruthy();
    expect(
      screen.getByText("Something went wrong on this page"),
    ).toBeTruthy();
    // The failing child must NOT still be visible.
    expect(screen.queryByText("child content")).toBeNull();
  });

  it("exposes an alert role and does not leak the stack trace to the user", () => {
    render(
      <AppErrorBoundary>
        <Boom shouldThrow />
      </AppErrorBoundary>,
    );

    expect(screen.getByRole("alert")).toBeTruthy();
    const text = screen.getByTestId("app-error-boundary").textContent ?? "";
    expect(text).not.toContain("kaboom");
    expect(text).not.toContain("at ");
    expect(text).not.toContain("Error:");
  });

  it("logs the error and component stack through the console", () => {
    render(
      <AppErrorBoundary label="unit-test">
        <Boom shouldThrow />
      </AppErrorBoundary>,
    );

    const logged = consoleErrorSpy.mock.calls
      .map((call: unknown[]) => String(call[0]))
      .join(" ");
    expect(logged).toContain("unit-test");
    expect(logged).toContain("Unhandled render error");
  });

  it("offers recovery controls", () => {
    render(
      <AppErrorBoundary>
        <Boom shouldThrow />
      </AppErrorBoundary>,
    );

    expect(screen.getByRole("button", { name: /try again/i })).toBeTruthy();
    const home = screen.getByRole("link", { name: /back to homepage/i });
    expect(home.getAttribute("href")).toBe("/");
  });

  it("recovers when 'Try again' resets the boundary and the child stops throwing", () => {
    let shouldThrow = true;
    const onReset = vi.fn(() => {
      shouldThrow = false;
    });

    const { rerender } = render(
      <AppErrorBoundary onReset={onReset}>
        <Boom shouldThrow={shouldThrow} />
      </AppErrorBoundary>,
    );

    expect(screen.getByTestId("app-error-boundary")).toBeTruthy();

    // Click recovery: onReset fires and the boundary clears its error state.
    screen.getByRole("button", { name: /try again/i }).click();
    rerender(
      <AppErrorBoundary onReset={onReset}>
        <Boom shouldThrow={false} />
      </AppErrorBoundary>,
    );

    expect(onReset).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId("app-error-boundary")).toBeNull();
    expect(screen.getByText("child content")).toBeTruthy();
  });
});
