import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { renderHook } from "@testing-library/react";
import { usePageMeta, routeMetaFor } from "../usePageMeta";

function metaContent(selector: string): string | null {
  return document.head.querySelector(selector)?.getAttribute("content") ?? null;
}

describe("B10 route metadata", () => {
  beforeEach(() => {
    document.title = "";
    document.head
      .querySelectorAll('meta[name="description"], meta[property^="og:"]')
      .forEach((el) => el.remove());
  });

  afterEach(() => {
    document.head
      .querySelectorAll('meta[name="description"], meta[property^="og:"]')
      .forEach((el) => el.remove());
  });

  it("applies a meaningful default on the homepage", () => {
    renderHook(() => usePageMeta("/"));
    expect(document.title).toBe("MasterInTech — Technology Education");
    expect(metaContent('meta[name="description"]')).toBeTruthy();
    expect(metaContent('meta[property="og:title"]')).toBe("MasterInTech — Technology Education");
    expect(metaContent('meta[property="og:site_name"]')).toBe("MasterInTech");
    expect(metaContent('meta[property="og:type"]')).toBe("website");
  });

  it("gives the catalog a route-appropriate title", () => {
    renderHook(() => usePageMeta("/courses"));
    expect(document.title).toBe("MasterInTech — Course Catalog");
  });

  it("titles a course detail page and marks it as an article", () => {
    // Mirrors App.tsx: the router passes routeMetaFor(pathname) as overrides.
    renderHook(() => usePageMeta("/courses/12", routeMetaFor("/courses/12")));
    expect(document.title).toBe("MasterInTech — Course Details");
    expect(metaContent('meta[property="og:type"]')).toBe("article");
  });

  it("covers the public informational routes", () => {
    for (const [path, title] of [
      ["/about", "MasterInTech — About Us"],
      ["/contact", "MasterInTech — Contact"],
      ["/instructors", "MasterInTech — Instructors"],
      ["/faq", "MasterInTech — FAQ"],
      ["/placements", "MasterInTech — Placements"],
      ["/verify-certificate", "MasterInTech — Verify Certificate"],
      ["/login", "MasterInTech — Sign In"],
    ] as const) {
      const { unmount } = renderHook(() => usePageMeta(path));
      expect(document.title).toBe(title);
      unmount();
    }
  });

  it("never leaves a route on the scaffold default title", () => {
    const paths = [
      "/", "/courses", "/courses/1", "/events", "/about", "/contact",
      "/instructors", "/resources", "/placements", "/corporate-partner",
      "/faq", "/verify-certificate", "/verify-certificate/MIT-1",
      "/login", "/register", "/forgot-password", "/reset-password",
      "/student", "/student/courses/11", "/student/courses/11/lessons",
      "/student/certificates/MIT-1", "/tutor", "/tutor/courses",
      "/company", "/admin/users", "/ai-assistant",
    ];
    for (const p of paths) {
      const { unmount } = renderHook(() => usePageMeta(p));
      expect(document.title).not.toBe("frontend");
      expect(document.title).not.toBe("Vite + React");
      expect(document.title.startsWith("MasterInTech")).toBe(true);
      expect(document.title.length).toBeGreaterThan("MasterInTech".length);
      unmount();
    }
  });

  it("keeps authenticated routes generic and description-free", () => {
    const { unmount } = renderHook(() =>
      usePageMeta("/student/courses/11", routeMetaFor("/student/courses/11")),
    );
    expect(document.title).toBe("MasterInTech — My Course");
    unmount();

    renderHook(() => usePageMeta("/student", routeMetaFor("/student")));
    expect(document.title).toBe("MasterInTech — Student Dashboard");
    // og:description must be removed rather than left stale.
    expect(metaContent('meta[property="og:description"]')).toBeNull();
  });

  it("resolves metadata from the route table", () => {
    expect(routeMetaFor("/")).toEqual({});
    expect(routeMetaFor("/courses/9")).toEqual({ title: "Course Details", type: "article" });
    expect(routeMetaFor("/student").private).toBe(true);
    expect(routeMetaFor("/admin/batches").private).toBe(true);
    expect(routeMetaFor("/tutor/courses").private).toBe(true);
    // More specific student paths must win over the general /student prefix.
    expect(routeMetaFor("/student/courses/11/lessons").title).toBe("Lessons");
    expect(routeMetaFor("/student/certificates/MIT-1").title).toBe("My Certificate");
  });

  it("prefixes route-table titles with the site name", () => {
    // Every private/override title must render as "<Site> — <title>", otherwise a
    // bare tab title like "My Course" would leak into the browser chrome.
    renderHook(() => usePageMeta("/student/courses/11", routeMetaFor("/student/courses/11")));
    expect(document.title).toBe("MasterInTech — My Course");
  });

  it("does not republish an identifier-bearing path as og:url on private routes", () => {
    renderHook(() => usePageMeta("/student/certificates/MIT-2026-ABC", routeMetaFor("/student/certificates/MIT-2026-ABC")));
    // The certificate code is user data, so no og:url may name it.
    expect(metaContent('meta[property="og:url"]')).toBeNull();
  });

  it("publishes og:url for public routes and clears it again on private ones", () => {
    renderHook(() => usePageMeta("/courses/12", routeMetaFor("/courses/12")));
    expect(metaContent('meta[property="og:url"]')).toBe("/courses/12");

    // Navigating to a private route must not leave the public URL behind.
    renderHook(() => usePageMeta("/student", routeMetaFor("/student")));
    expect(metaContent('meta[property="og:url"]')).toBeNull();
  });
});