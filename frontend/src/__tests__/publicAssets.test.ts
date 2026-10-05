import { describe, it, expect } from "vitest";

/**
 * B09: `/robots.txt` must be served as a real robots.txt, not swallowed by the
 * SPA rewrite (`try_files $uri $uri/ /index.html` in deploy/frontend/nginx.conf).
 *
 * Vite copies everything in `public/` to the build output root, so a file at
 * `public/robots.txt` is what guarantees `dist/robots.txt` exists in production.
 * The glob below reads the file through Vite's own asset pipeline, so this test
 * needs no Node type definitions.
 *
 * Guards against the file being deleted or replaced with markup, which
 * silently reintroduces the `text/html` fallback.
 */
const files = import.meta.glob("../../public/robots.txt", {
  query: "?raw",
  import: "default",
  eager: true,
}) as Record<string, string>;

const path = "../../public/robots.txt";
const body = files[path];

describe("B09 robots.txt", () => {
  it("exists as a static asset in public/ so Vite copies it to dist/", () => {
    expect(Object.keys(files)).toContain(path);
    expect(typeof body).toBe("string");
    expect(body.length).toBeGreaterThan(0);
  });

  it("is a valid, conservative robots.txt rather than markup", () => {
    // Must not be HTML — this is the exact regression being guarded.
    expect(body).not.toMatch(/<!doctype html|<html[\s>]/i);

    // A robots.txt needs at least one group with a User-agent line.
    expect(body).toMatch(/^\s*User-agent:\s*\*/im);

    // Conservative policy: the public catalog must stay crawlable.
    expect(body).not.toMatch(/^\s*Disallow:\s*\/\s*$/im);
  });

  it("declares no hard-coded origin", () => {
    // DEPLOYMENT.md: "Generic origins are not hard-coded anywhere in the
    // codebase." A Sitemap: line with a hostname would violate that rule.
    expect(body).not.toMatch(/^\s*Sitemap:/im);
  });
});