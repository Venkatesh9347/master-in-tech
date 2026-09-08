import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";

vi.mock("../../../components/Navbar", () => ({ default: () => null }));
vi.mock("../../../components/Footer", () => ({ default: () => null }));

const mocks = vi.hoisted(() => {
  const instanceCall = vi.fn((config: unknown) => Promise.resolve(config));

  const apiInstance = Object.assign(
    (config: unknown) => instanceCall(config),
    {
      interceptors: {
        request: { use: (_handler: unknown) => {} },
        response: { use: (_ok: unknown, _err: unknown) => {} },
      },
      get: vi.fn().mockResolvedValue({ data: {} }),
      post: vi.fn(),
    }
  );

  return { apiInstance, instanceCall };
});

vi.mock("axios", () => ({ default: { create: () => mocks.apiInstance } }));

vi.mock("../../../context/useAuth", () => ({
  useAuth: () => ({ user: { id: 1, name: "Test User", email: "test@example.com" } }),
}));

import Checkout from "../Checkout";

const orderCreated = {
  message: "Order created.",
  provider: "stub",
  key_id: null,
  theme: "#2563eb",
  course: { id: 42, title: "Full Stack Development", slug: "full-stack" },
  order: {
    order_id: "order_abc",
    payment_id: "stub_pay_token",
    amount: 250000,
    currency: "INR",
    status: "stub_created",
  },
};

const paidOrder = {
  message: "Payment confirmed.",
  order: { order_id: "order_abc", status: "paid", paid_at: null, course_id: 42 },
};

function renderCheckout() {
  return render(
    <MemoryRouter initialEntries={["/student/checkout/42"]}>
      <Routes>
        <Route path="/student/checkout/:courseId" element={<Checkout />} />
        <Route path="/student/courses/:courseId/lessons" element={<div>LESSONS-LANDING</div>} />
      </Routes>
    </MemoryRouter>
  );
}

describe("Checkout", () => {
  beforeEach(() => {
    mocks.apiInstance.post.mockReset();
    mocks.apiInstance.get.mockReset();
    mocks.apiInstance.get.mockResolvedValue({ data: {} });
  });

  afterEach(() => {
    cleanup();
  });

  it("creates an order from the server for the course", async () => {
    mocks.apiInstance.post.mockResolvedValueOnce({ data: orderCreated });

    renderCheckout();

    await waitFor(() => {
      expect(mocks.apiInstance.post).toHaveBeenCalledWith("/payments/order", { course_id: 42 });
    });

    expect(await screen.findByText("Full Stack Development")).toBeTruthy();
    expect(screen.getByText("INR 2500.00")).toBeTruthy();
  });

  it("stub flow confirms server-side and navigates only on server-paid status", async () => {
    mocks.apiInstance.post
      .mockResolvedValueOnce({ data: orderCreated })
      .mockResolvedValueOnce({ data: paidOrder });

    renderCheckout();

    const button = (await screen.findByText("Confirm payment")) as HTMLButtonElement;
    fireEvent.click(button);

    await waitFor(() => {
      expect(mocks.apiInstance.post).toHaveBeenCalledWith("/payments/confirm", {
        order_id: "order_abc",
        payment_id: "stub_pay_token",
        signature: undefined,
      });
    });

    await waitFor(() => {
      expect(screen.getByText("LESSONS-LANDING")).toBeTruthy();
    });
  });

  it("shows an error when the server rejects confirm without navigating away", async () => {
    mocks.apiInstance.post
      .mockResolvedValueOnce({ data: orderCreated })
      .mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 422, data: { message: "Payment could not be confirmed.", error: "invalid_signature" } },
      });

    renderCheckout();

    const button = (await screen.findByText("Confirm payment")) as HTMLButtonElement;
    fireEvent.click(button);

    await waitFor(() => {
      expect(screen.getByText(/could not be confirmed/i)).toBeTruthy();
    });

    expect(screen.queryByText("LESSONS-LANDING")).toBeNull();
  });
});