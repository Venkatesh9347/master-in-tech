import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
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

import AdminRefunds from "../admin/AdminRefunds";

const txA = {
  id: 1,
  provider: "stub",
  order_id: "order_aaa",
  payment_id: "pay_aaa",
  user: { id: 11, name: "Alice Learner", email: "alice@example.com" },
  course: { id: 31, title: "Full Stack" },
  amount: 100000,
  currency: "INR",
  status: "paid",
  refunded_amount: 0,
  remaining_refundable: 100000,
  paid_at: null,
  created_at: null,
};

const txB = { ...txA, id: 2, payment_id: "pay_bbb", status: "refunded", refunded_amount: 100000, remaining_refundable: 0, user: { id: 12, name: "Bob Learner", email: "bob@example.com" } };

const detailA = {
  ...txA,
  refunds: [
    {
      id: 9,
      payment_transaction_id: 1,
      provider: "stub",
      provider_refund_id: "stub_rfnd_1",
      amount: 20000,
      currency: "INR",
      status: "succeeded",
      source: "admin",
      created_at: null,
    },
  ],
};

function ledger(items: unknown[]) {
  return {
    data: { current_page: 1, data: items, per_page: 15, total: items.length, last_page: 1 },
  };
}

function renderDesk() {
  render(
    <MemoryRouter>
      <AdminRefunds />
    </MemoryRouter>
  );
}

describe("AdminRefunds page", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
    mocks.apiInstance.post.mockReset();
    if (typeof globalThis.crypto === "undefined") {
      vi.stubGlobal("crypto", { randomUUID: () => "12345678-1234-1234-1234-123456789012" });
    } else if (!globalThis.crypto.randomUUID) {
      vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue("12345678-1234-1234-1234-123456789012");
    }
  });

  it("renders ledger rows from the paginated envelope", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/payments")) {
        return Promise.resolve(ledger([txA, txB]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("pay_aaa")).toBeTruthy());
    expect(screen.getByText("Alice Learner")).toBeTruthy();
    expect(screen.getByText("Bob Learner")).toBeTruthy();
    expect(screen.getByText("pay_bbb")).toBeTruthy();
  });

  it("opens the detail drawer with refund history", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/payments/1")) {
        return Promise.resolve({ data: { payment: detailA } });
      }
      if (String(url).startsWith("/admin/payments")) {
        return Promise.resolve(ledger([txA]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("pay_aaa")).toBeTruthy());
    fireEvent.click(screen.getByText("Refund"));

    await waitFor(() => expect(screen.getByText("Refund history")).toBeTruthy());
    expect(
      screen.getByText((_, el) => el?.textContent === "· succeeded · admin · stub_rfnd_1")
    ).toBeTruthy();
  });

  it("rejects a short reason without posting", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/payments/1")) {
        return Promise.resolve({ data: { payment: { ...detailA, refunds: [] } } });
      }
      if (String(url).startsWith("/admin/payments")) {
        return Promise.resolve(ledger([txA]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("pay_aaa")).toBeTruthy());
    fireEvent.click(screen.getByText("Refund"));
    await waitFor(() => expect(screen.getByText("Initiate Refund")).toBeTruthy());
    fireEvent.click(screen.getByText("Initiate Refund"));

    fireEvent.change(screen.getByPlaceholderText("Why is this refund being issued?"), {
      target: { value: "short" },
    });
    fireEvent.click(screen.getByText("Confirm Refund ✓"));

    await waitFor(() =>
      expect(screen.getByText("Provide a reason of at least 10 characters.")).toBeTruthy()
    );
    expect(mocks.apiInstance.post).not.toHaveBeenCalled();
  });

  it("maps provider errors to safe messages", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/payments/1")) {
        return Promise.resolve({ data: { payment: { ...detailA, refunds: [] } } });
      }
      if (String(url).startsWith("/admin/payments")) {
        return Promise.resolve(ledger([txA]));
      }
      return Promise.resolve({ data: [] });
    });
    mocks.apiInstance.post.mockRejectedValue({
      response: { status: 502, data: { message: "Refund could not be processed.", error: "provider_failed" } },
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("pay_aaa")).toBeTruthy());
    fireEvent.click(screen.getByText("Refund"));
    await waitFor(() => expect(screen.getByText("Initiate Refund")).toBeTruthy());
    fireEvent.click(screen.getByText("Initiate Refund"));

    fireEvent.change(screen.getByPlaceholderText("Why is this refund being issued?"), {
      target: { value: "Duplicate charge confirmed by finance." },
    });
    fireEvent.click(screen.getByText("Confirm Refund ✓"));

    await waitFor(() =>
      expect(screen.getByText("The payment provider rejected the refund. It is safe to retry.")).toBeTruthy()
    );
  });

  it("posts amount, reason and idempotency key on success", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/payments/1")) {
        return Promise.resolve({ data: { payment: { ...detailA, refunds: [] } } });
      }
      if (String(url).startsWith("/admin/payments")) {
        return Promise.resolve(ledger([txA]));
      }
      return Promise.resolve({ data: [] });
    });
    mocks.apiInstance.post.mockImplementation((url: string, body: Record<string, unknown>) => {
      expect(url).toBe("/admin/payments/1/refund");
      expect(body.amount).toBe(25000);
      expect(typeof body.idempotency_key).toBe("string");
      expect(body.reason).toBe("Duplicate charge confirmed by finance.");
      return Promise.resolve({
        data: {
          refund: { amount: 25000, provider_refund_id: "stub_rfnd_x" },
          payment: { ...txA },
        },
      });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("pay_aaa")).toBeTruthy());
    fireEvent.click(screen.getByText("Refund"));
    await waitFor(() => expect(screen.getByText("Initiate Refund")).toBeTruthy());
    fireEvent.click(screen.getByText("Initiate Refund"));

    fireEvent.change(screen.getByPlaceholderText("e.g. 250.00"), {
      target: { value: "250" },
    });
    fireEvent.change(screen.getByPlaceholderText("Why is this refund being issued?"), {
      target: { value: "Duplicate charge confirmed by finance." },
    });
    fireEvent.click(screen.getByText("Confirm Refund ✓"));

    await waitFor(() => expect(mocks.apiInstance.post).toHaveBeenCalledTimes(1));
  });

  it("blocks amounts above the remaining balance client-side", async () => {
    mocks.apiInstance.get.mockImplementation((url: string) => {
      if (String(url).startsWith("/admin/payments/1")) {
        return Promise.resolve({ data: { payment: { ...detailA, refunds: [] } } });
      }
      if (String(url).startsWith("/admin/payments")) {
        return Promise.resolve(ledger([txA]));
      }
      return Promise.resolve({ data: [] });
    });

    renderDesk();

    await waitFor(() => expect(screen.getByText("pay_aaa")).toBeTruthy());
    fireEvent.click(screen.getByText("Refund"));
    await waitFor(() => expect(screen.getByText("Initiate Refund")).toBeTruthy());
    fireEvent.click(screen.getByText("Initiate Refund"));

    fireEvent.change(screen.getByPlaceholderText("e.g. 250.00"), {
      target: { value: "5000" },
    });
    fireEvent.change(screen.getByPlaceholderText("Why is this refund being issued?"), {
      target: { value: "Duplicate charge confirmed by finance." },
    });
    fireEvent.click(screen.getByText("Confirm Refund ✓"));

    await waitFor(() =>
      expect(screen.getByText(/exceeds the remaining refundable balance/)).toBeTruthy()
    );
    expect(mocks.apiInstance.post).not.toHaveBeenCalled();
  });
});
