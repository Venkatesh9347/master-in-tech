import { useCallback, useEffect, useState } from "react";
import { Navigate, useNavigate, useParams } from "react-router-dom";
import { useAuth } from "../../context/useAuth";
import API from "../../services/api";
import Navbar from "../../components/Navbar";
import Footer from "../../components/Footer";

interface PaymentOrder {
  order_id: string;
  payment_id: string;
  amount: number;
  currency: string;
  status: string;
  reused?: boolean;
}

interface CreateOrderResponse {
  message: string;
  provider: string;
  key_id: string | null;
  theme?: string;
  course: { id: number; title: string; slug: string };
  order: PaymentOrder;
}

interface ConfirmResponse {
  message: string;
  order: { order_id: string; status: string; paid_at?: string | null; course_id: number | null };
}

interface RazorpayCheckoutResponse {
  razorpay_payment_id: string;
  razorpay_signature: string;
}

interface RazorpayFailureResponse {
  error: { code?: string; description?: string };
}

declare global {
  interface Window {
    Razorpay?: new (options: Record<string, unknown>) => {
      on: (event: string, callback: (response: RazorpayFailureResponse) => void) => void;
      open: () => void;
    };
  }
}

// Razorpay Checkout.js is loaded lazily and cached once loaded.
const RAZORPAY_CHECKOUT_SRC = "https://checkout.razorpay.com/v1/checkout.js";

let checkoutScriptPromise: Promise<void> | null = null;

function loadRazorpayCheckout(): Promise<void> {
  if (typeof window !== "undefined" && window.Razorpay) {
    return Promise.resolve();
  }

  if (!checkoutScriptPromise) {
    checkoutScriptPromise = new Promise<void>((resolve, reject) => {
      const script = document.createElement("script");
      script.src = RAZORPAY_CHECKOUT_SRC;
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => {
        checkoutScriptPromise = null;
        reject(new Error("Could not load Razorpay Checkout.js"));
      };
      document.head.appendChild(script);
    });
  }

  return checkoutScriptPromise;
}

/**
 * Course checkout → Razorpay (real gateway) or Stub (local/tests).
 *
 * Security model:
 *  - The backend alone computes the amount from the course price.
 *  - The frontend never declares success: after Checkout.js returns a
 *    payment_id + signature, the server is asked to confirm (signature
 *    verified, payment fetched, order/amount/currency matched) and enrollment
 *    is activated only when the server reports the transaction paid.
 */
export default function Checkout() {
  const { courseId } = useParams<{ courseId: string }>();
  const { user } = useAuth();
  const navigate = useNavigate();

  const [order, setOrder] = useState<CreateOrderResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [paying, setPaying] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const userName = user?.name;
  const userEmail = user?.email;

  const confirmServerSide = useCallback(
    async (paymentId: string, signature?: string) => {
      if (!order) return;

      setPaying(true);
      setError(null);

      try {
        const { data } = await API.post<ConfirmResponse>("/payments/confirm", {
          order_id: order.order.order_id,
          payment_id: paymentId,
          signature,
        });

        if (data.order.status === "paid") {
          navigate(`/student/courses/${order.course.id}/lessons`, { replace: true });
          return;
        }

        setError(`Payment is ${data.order.status || "pending"}. Please try again.`);
      } catch (err: unknown) {
        const response = (err as { response?: { data?: { message?: string; error?: string } } })
          ?.response?.data;
        setError(response?.message || `Payment could not be confirmed (${response?.error || "error"}).`);
      } finally {
        setPaying(false);
      }
    },
    [navigate, order]
  );

  const payWithRazorpay = useCallback(async () => {
    if (!order || order.provider !== "razorpay" || !order.key_id) return;

    setError(null);
    setPaying(true);

    try {
      await loadRazorpayCheckout();

      const Razorpay = window.Razorpay;

      if (!Razorpay) {
        throw new Error("Razorpay Checkout is unavailable.");
      }

      const checkout = new Razorpay({
        key: order.key_id,
        amount: order.order.amount,
        currency: order.order.currency,
        order_id: order.order.order_id,
        name: "MasterInTech",
        description: `Enrolment fee for ${order.course.title}`,
        prefill: {
          name: userName || "",
          email: userEmail || "",
        },
        theme: order.theme ? { color: order.theme } : undefined,
        handler: async (response: RazorpayCheckoutResponse) => {
          await confirmServerSide(response.razorpay_payment_id, response.razorpay_signature);
        },
        modal: {
          ondismiss: () => setPaying(false),
        },
      });

      checkout.on("payment.failed", (response: RazorpayFailureResponse) => {
        setPaying(false);
        setError(
          response?.error?.description || "Payment failed. Please try again or use another method."
        );
      });

      checkout.open();
    } catch (err: unknown) {
      setPaying(false);
      const message = (err as { message?: string })?.message;
      setError(message || "Unable to start the payment. Please try again.");
    }
  }, [confirmServerSide, order, userName, userEmail]);

  const proceedToPay = useCallback(() => {
    if (!order) return;

    if (order.provider === "razorpay") {
      void payWithRazorpay();
      return;
    }

    // Stub provider: authoritative server-side confirm, no signature required.
    void confirmServerSide(order.order.payment_id);
  }, [confirmServerSide, order, payWithRazorpay]);

  useEffect(() => {
    if (!courseId) return;

    let cancelled = false;

    setLoading(true);
    setError(null);

    API.post<CreateOrderResponse>("/payments/order", { course_id: Number(courseId) })
      .then(({ data }) => {
        if (cancelled) return;
        setOrder(data);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const message = (err as { response?: { data?: { message?: string } } })?.response?.data
          ?.message;
        setError(message || "Failed to create a payment order for this course.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [courseId]);

  if (!courseId) {
    return <Navigate to="/courses" replace />;
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="mx-auto max-w-2xl px-4 py-10">
        <h1 className="mb-6 text-3xl font-bold text-gray-900">Checkout</h1>

        {error && (
          <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
        )}

        {loading ? (
          <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
            Preparing your order…
          </div>
        ) : order ? (
          <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 className="mb-4 text-lg font-semibold text-gray-900">{order.course.title}</h2>
            <dl className="mb-6 space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-gray-500">Amount</dt>
                <dd className="font-semibold text-gray-900">
                  {order.order.currency} {(order.order.amount / 100).toFixed(2)}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-500">Status</dt>
                <dd className="text-gray-900">{order.order.status}</dd>
              </div>
              {order.order.reused && (
                <div className="flex justify-between">
                  <dt className="text-gray-500">Note</dt>
                  <dd className="text-gray-600">Existing order (you have not been charged again).</dd>
                </div>
              )}
            </dl>
            <button
              onClick={proceedToPay}
              disabled={paying}
              className="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {paying
                ? "Processing…"
                : order.provider === "razorpay"
                  ? "Pay securely"
                  : "Confirm payment"}
            </button>
          </div>
        ) : (
          <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-700">
            This course could not be checked out. It may be unpublished or unavailable.
          </div>
        )}

        <p className="mt-6 text-xs text-gray-500">
          Signed in as {user?.name || user?.email}. Whether the payment really succeeded is
          verified by the server before you get access — the browser never decides that on its own.
        </p>
      </div>
      <Footer />
    </div>
  );
}