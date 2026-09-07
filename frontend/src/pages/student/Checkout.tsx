import { useState } from "react";
import { useAuth } from "../../context/useAuth";
import API from "../../services/api";
import Navbar from "../../components/Navbar";
import Footer from "../../components/Footer";

interface OrderResponse {
  message: string;
  order: {
    order_id: string;
    payment_id: string;
    amount: number;
    currency: string;
    status: string;
    reused?: boolean;
    paid_at?: string;
  };
}

/**
 * Student checkout — creates and authoritatively confirms a payment.
 *
 * Security model (GA blocker R1):
 *  - The frontend NEVER declares success on its own.
 *  - An order's payment id comes from the server (createOrder).
 *  - Payment is only marked paid by GET/POST /api/payments/confirm, which
 *    performs server-side provider verification (order/amount/currency match,
 *    captured status) before any paid transition.
 */
export default function Checkout() {
  const { user } = useAuth();
  const [amount, setAmount] = useState("");
  const [description, setDescription] = useState("");
  const [order, setOrder] = useState<OrderResponse["order"] | null>(null);
  const [creating, setCreating] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [confirmation, setConfirmation] = useState<OrderResponse["order"] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const handleCreateOrder = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setCreating(true);

    try {
      const amountNumber = parseFloat(amount);
      if (!amountNumber || amountNumber <= 0) {
        setError("Please enter a valid amount.");
        return;
      }

      const { data } = await API.post<OrderResponse>("/payments/order", {
        amount: amountNumber,
        description: description.trim() || undefined,
      });
      setOrder(data.order);
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(message || "Failed to create payment order.");
    } finally {
      setCreating(false);
    }
  };

  const handleConfirm = async () => {
    if (!order) return;
    setError(null);
    setConfirming(true);

    try {
      const { data } = await API.post<{ order: OrderResponse["order"] }>("/payments/confirm", {
        order_id: order.order_id,
        payment_id: order.payment_id,
      });
      setConfirmation(data.order);
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(message || "Payment could not be confirmed.");
    } finally {
      setConfirming(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="mx-auto max-w-2xl px-4 py-10">
        <h1 className="mb-6 text-3xl font-bold text-gray-900">Checkout</h1>

        {error && (
          <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
        )}

        {confirmation ? (
          <div className="rounded-lg border border-green-200 bg-green-50 p-6">
            <h2 className="mb-2 text-xl font-semibold text-green-800">Payment confirmed</h2>
            <p className="text-sm text-green-700">
              Order {confirmation.order_id} is now <strong>{confirmation.status}</strong>.
            </p>
            {confirmation.paid_at && (
              <p className="mt-1 text-sm text-green-700">
                Paid at {new Date(confirmation.paid_at).toLocaleString()}
              </p>
            )}
          </div>
        ) : order ? (
          <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 className="mb-4 text-lg font-semibold text-gray-900">Review your order</h2>
            <dl className="mb-6 space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-gray-500">Order ID</dt>
                <dd className="font-mono text-gray-900">{order.order_id}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-500">Amount</dt>
                <dd className="font-semibold text-gray-900">
                  {order.currency} {(order.amount / 100).toFixed(2)}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-500">Status</dt>
                <dd className="text-gray-900">{order.status}</dd>
              </div>
              {order.reused && (
                <div className="flex justify-between">
                  <dt className="text-gray-500">Note</dt>
                  <dd className="text-gray-600">Existing order (not re-charged).</dd>
                </div>
              )}
            </dl>
            <button
              onClick={handleConfirm}
              disabled={confirming}
              className="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {confirming ? "Confirming…" : "Confirm payment"}
            </button>
          </div>
        ) : (
          <form onSubmit={handleCreateOrder} className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div className="mb-4">
              <label htmlFor="amount" className="mb-1 block text-sm font-medium text-gray-700">
                Amount (INR)
              </label>
              <input
                id="amount"
                type="number"
                min="1"
                step="0.01"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                placeholder="e.g. 2500"
                required
              />
            </div>
            <div className="mb-6">
              <label htmlFor="description" className="mb-1 block text-sm font-medium text-gray-700">
                Description (optional)
              </label>
              <input
                id="description"
                type="text"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                placeholder="e.g. Course enrolment fee"
              />
            </div>
            <button
              type="submit"
              disabled={creating}
              className="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {creating ? "Creating order…" : "Create order"}
            </button>
          </form>
        )}

        <p className="mt-6 text-xs text-gray-500">
          Signed in as {user?.name || user?.email}. Payment status is verified by the server before
          it is marked as paid; the browser never declares a payment successful on its own.
        </p>
      </div>
      <Footer />
    </div>
  );
}