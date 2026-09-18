import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import { render, screen, waitFor, cleanup, fireEvent, within } from "@testing-library/react";
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

import AdminCertificates from "../admin/AdminCertificates";

const certActive = {
  id: 7,
  certificate_code: "MIT-2026-ACTIVE123456",
  status: "active",
  issued_at: "2026-09-01T00:00:00.000Z",
  revoked_at: null,
  revoked_by: null,
  revocation_reason: null,
  user: { id: 11, name: "Alice Learner", email: "alice@example.com" },
  course: { id: 31, title: "Full Stack" },
  created_at: "2026-09-01T00:00:00.000Z",
};

const certRevoked = {
  id: 8,
  certificate_code: "MIT-2026-REVOKED654321",
  status: "revoked",
  issued_at: "2026-08-01T00:00:00.000Z",
  revoked_at: "2026-09-02T00:00:00.000Z",
  revoked_by: 3,
  revocation_reason: "Academic integrity violation confirmed.",
  user: { id: 12, name: "Bob Learner", email: "bob@example.com" },
  course: { id: 32, title: "Cloud" },
  created_at: "2026-08-01T00:00:00.000Z",
};

const detailActive = { ...certActive, revoked_by_user: null };
const detailRevoked = {
  ...certRevoked,
  revoked_by_user: { id: 3, name: "Rita Admin" },
};

function registry(items: unknown[]) {
  return {
    data: { current_page: 1, data: items, per_page: 15, total: items.length, last_page: 1 },
  };
}

function renderDesk() {
  render(
    <MemoryRouter>
      <AdminCertificates />
    </MemoryRouter>
  );
}

describe("AdminCertificates page", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
    mocks.apiInstance.post.mockReset();
  });

  it("renders registry rows from the paginated envelope", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive, certRevoked]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    expect(screen.getByText("Alice Learner")).toBeTruthy();
    expect(screen.getByText("Bob Learner")).toBeTruthy();
    expect(screen.getByText("MIT-2026-REVOKED654321")).toBeTruthy();
  });

  it("offers revoke only for active certificates", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive, certRevoked]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    // One row-level Revoke button (active row only).
    expect(screen.getAllByText("Revoke")).toHaveLength(1);
  });

  it("opens the detail modal with safe fields and no sensitive data", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));

    await waitFor(() => expect(screen.getByText("Revocation reason")).toBeTruthy());
    expect(screen.getByText("alice@example.com")).toBeTruthy();
    expect(document.body.textContent).not.toContain("pdf_path");
    expect(document.body.textContent).not.toContain("secret");
  });

  it("shows revoking admin and reason for revoked certificates", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/8") {
        return Promise.resolve({ data: { certificate: detailRevoked } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certRevoked]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-REVOKED654321")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));

    await waitFor(() => expect(screen.getByText("Rita Admin")).toBeTruthy());
    expect(screen.getByText("Academic integrity violation confirmed.")).toBeTruthy();
    expect(screen.getByText(/Already revoked/)).toBeTruthy();
    expect(screen.queryByText("Revoke Certificate")).toBeNull();
  });

  it("rejects a short reason without posting", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));
    await waitFor(() => expect(screen.getByText("Revoke Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("Revoke Certificate"));

    fireEvent.change(screen.getByPlaceholderText("Why is this certificate being revoked?"), {
      target: { value: "short" },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));

    await waitFor(() =>
      expect(screen.getByText("Provide a reason of at least 10 characters.")).toBeTruthy()
    );
    expect(mocks.apiInstance.post).not.toHaveBeenCalled();
  });

  it("rejects an over-long reason without posting", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));
    await waitFor(() => expect(screen.getByText("Revoke Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("Revoke Certificate"));

    fireEvent.change(screen.getByPlaceholderText("Why is this certificate being revoked?"), {
      target: { value: "x".repeat(501) },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));

    await waitFor(() =>
      expect(screen.getByText("Reason must be no more than 500 characters.")).toBeTruthy()
    );
    expect(mocks.apiInstance.post).not.toHaveBeenCalled();
  });

  it("posts the numeric id and trimmed reason on success", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });
    mocks.apiInstance.post.mockImplementation((url: string, body: Record<string, unknown>) => {
      expect(url).toBe("/admin/certificates/7/revoke");
      expect(body).toEqual({ reason: "Academic integrity violation confirmed by review." });
      return Promise.resolve({
        data: {
          message: "Certificate revoked.",
          certificate: {
            certificate_code: certActive.certificate_code,
            status: "revoked",
            revoked_at: "2026-09-18T00:00:00.000Z",
            revoked_by: 3,
            revocation_reason: "Academic integrity violation confirmed by review.",
          },
        },
      });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));
    await waitFor(() => expect(screen.getByText("Revoke Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("Revoke Certificate"));

    fireEvent.change(screen.getByPlaceholderText("Why is this certificate being revoked?"), {
      target: { value: "  Academic integrity violation confirmed by review.  " },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));

    await waitFor(() => expect(mocks.apiInstance.post).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(screen.getByText(/Certificate MIT-2026-ACTIVE123456 revoked\./)).toBeTruthy()
    );
  });

  it("handles an already-revoked response as revoked state", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });
    mocks.apiInstance.post.mockResolvedValue({
      data: {
        message: "Certificate already revoked.",
        certificate: {
          certificate_code: certActive.certificate_code,
          status: "revoked",
          revoked_at: "2026-09-10T00:00:00.000Z",
          revoked_by: 3,
          revocation_reason: "Original reason kept by first revocation.",
        },
      },
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));
    await waitFor(() => expect(screen.getByText("Revoke Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("Revoke Certificate"));

    fireEvent.change(screen.getByPlaceholderText("Why is this certificate being revoked?"), {
      target: { value: "A different later reason text here." },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));

    await waitFor(() =>
      expect(screen.getByText(/was already revoked/)).toBeTruthy()
    );
  });

  it("maps 404 to a safe not-found message", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });
    mocks.apiInstance.post.mockRejectedValue({
      response: { status: 404, data: { message: "Not found." } },
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));
    await waitFor(() => expect(screen.getByText("Revoke Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("Revoke Certificate"));

    fireEvent.change(screen.getByPlaceholderText("Why is this certificate being revoked?"), {
      target: { value: "Academic integrity violation confirmed." },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));

    await waitFor(() =>
      expect(
        screen.getByText("Certificate not found. It may have been deleted with its user or course.")
      ).toBeTruthy()
    );
  });

  it("maps 403 and 401 to safe messages", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));
    await waitFor(() => expect(screen.getByText("Revoke Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("Revoke Certificate"));

    mocks.apiInstance.post.mockRejectedValueOnce({
      response: { status: 403, data: { message: "Unauthorized. Admin access required." } },
    });
    fireEvent.change(screen.getByPlaceholderText("Why is this certificate being revoked?"), {
      target: { value: "Academic integrity violation confirmed." },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));
    await waitFor(() =>
      expect(screen.getByText("You don't have permission to revoke certificates.")).toBeTruthy()
    );

    mocks.apiInstance.post.mockRejectedValueOnce({
      response: { status: 401, data: { message: "Unauthenticated." } },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));
    await waitFor(() =>
      expect(screen.getByText("Your session has expired. Please log in again.")).toBeTruthy()
    );
  });

  it("maps 422 field errors to the first message", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url) === "/admin/certificates/7") {
        return Promise.resolve({ data: { certificate: detailActive } });
      }
      if (String(url).startsWith("/admin/certificates")) {
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });
    mocks.apiInstance.post.mockRejectedValue({
      response: {
        status: 422,
        data: { message: "The reason field is required.", errors: { reason: ["The reason field is required."] } },
      },
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());
    fireEvent.click(screen.getByText("View"));
    await waitFor(() => expect(screen.getByText("Revoke Certificate")).toBeTruthy());
    fireEvent.click(screen.getByText("Revoke Certificate"));

    fireEvent.change(screen.getByPlaceholderText("Why is this certificate being revoked?"), {
      target: { value: "Academic integrity violation confirmed." },
    });
    fireEvent.click(screen.getByText("Confirm Revocation ✓"));

    await waitFor(() =>
      expect(screen.getByText("The reason field is required.")).toBeTruthy()
    );
  });

  it("forwards search and status filter params to the list endpoint", async () => {
    const seen: string[] = [];
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/certificates")) {
        seen.push(String(url));
        // Detail urls also start with the prefix; answer both shapes safely.
        if (/\/admin\/certificates\/\d+$/.test(String(url).split("?")[0])) {
          return Promise.resolve({ data: { certificate: detailActive } });
        }
        return Promise.resolve(registry([certActive]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("MIT-2026-ACTIVE123456")).toBeTruthy());

    fireEvent.change(screen.getByPlaceholderText("Search code, student, email, course…"), {
      target: { value: "MIT-2026-ACTIVE" },
    });

    await waitFor(() =>
      expect(seen.some((u) => u.includes("search=MIT-2026-ACTIVE"))).toBe(true)
    );

    const statusSelect = screen.getByRole("combobox");
    fireEvent.change(statusSelect, { target: { value: "revoked" } });

    await waitFor(() =>
      expect(seen.some((u) => u.includes("status=revoked"))).toBe(true)
    );

    // Rendered table scope check: status pill text comes from row data.
    const table = screen.getByRole("table");
    expect(within(table).getByText("active")).toBeTruthy();
  });
});
