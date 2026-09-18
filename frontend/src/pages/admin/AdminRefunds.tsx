import { useEffect, useState } from 'react';
import API from '../../services/api';
import Pagination from '../../components/Pagination';
import { usePagedQuery } from '../../hooks/usePagedQuery';
import {
  formatPaise,
  REFUND_ERROR_MESSAGES,
  type RefundDetail,
  type RefundErrorCode,
  type RefundListItem,
} from '../../types/refund';

type Phase = 'detail' | 'confirm';

export default function AdminRefunds() {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [selected, setSelected] = useState<RefundDetail | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [phase, setPhase] = useState<Phase>('detail');
  const [amountInput, setAmountInput] = useState('');
  const [reasonInput, setReasonInput] = useState('');
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [successMsg, setSuccessMsg] = useState('');
  const [errorMsg, setErrorMsg] = useState('');

  // Payment ledger: server-paginated; filter/search changes reset to page 1.
  const {
    items: payments,
    meta: paymentsMeta,
    loading,
    setPage: setPaymentsPage,
    reload: reloadPayments,
  } = usePagedQuery<RefundListItem>(
    '/admin/payments',
    {
      search: search.trim() || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
    },
    {
      errorMessage: 'Failed to load payment ledger.',
    }
  );

  const closeDrawer = () => {
    setSelected(null);
    setPhase('detail');
    setAmountInput('');
    setReasonInput('');
    setFormError('');
    setDetailError('');
  };

  const openDetail = async (id: number) => {
    closeDrawer();
    setLoadingDetail(true);
    try {
      const res = await API.get<{ payment: RefundDetail }>(`/admin/payments/${id}`);
      setSelected(res.data.payment);
    } catch {
      setDetailError('Failed to load payment details.');
    } finally {
      setLoadingDetail(false);
    }
  };

  // Fresh idempotency identity per refund attempt; a retry of the same
  // attempt reuses it so the server returns the original row.
  const startRefund = () => {
    setFormError('');
    setSuccessMsg('');
    setErrorMsg('');
    setPhase('confirm');
  };

  const submitRefund = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selected || submitting) return;

    const trimmedReason = reasonInput.trim();
    if (trimmedReason.length < 10) {
      setFormError('Provide a reason of at least 10 characters.');
      return;
    }

    let amount: number | undefined;
    if (amountInput.trim() !== '') {
      const rupees = Number(amountInput);
      if (!Number.isFinite(rupees) || rupees <= 0) {
        setFormError('Enter an amount greater than zero, or leave blank for a full refund.');
        return;
      }
      amount = Math.round(rupees * 100);
      if (amount > selected.remaining_refundable) {
        setFormError(
          `Amount exceeds the remaining refundable balance of ${formatPaise(
            selected.remaining_refundable,
            selected.currency
          )}.`
        );
        return;
      }
    }

    setSubmitting(true);
    setFormError('');
    try {
      const res = await API.post(`/admin/payments/${selected.id}/refund`, {
        ...(amount !== undefined ? { amount } : {}),
        idempotency_key: crypto.randomUUID(),
        reason: trimmedReason,
      });
      const refund = res.data.refund as { amount: number; provider_refund_id?: string };
      setSuccessMsg(
        `Refund of ${formatPaise(refund.amount, selected.currency)} initiated (provider ref ${
          refund.provider_refund_id ?? 'pending'
        }).`
      );
      setPhase('detail');
      setAmountInput('');
      setReasonInput('');
      // Refresh detail (history + balances) and the ledger row.
      await openDetail(selected.id);
      reloadPayments();
    } catch (err: unknown) {
      const response = err as {
        response?: { status?: number; data?: { error?: string; message?: string; errors?: Record<string, string[]> } };
      };
      const code = response.response?.data?.error as RefundErrorCode | undefined;
      const fieldErrors = response.response?.data?.errors;
      if (fieldErrors) {
        const first = Object.values(fieldErrors).flat()[0];
        setFormError(typeof first === 'string' ? first : 'Refund request was rejected.');
      } else if (code && REFUND_ERROR_MESSAGES[code]) {
        setFormError(REFUND_ERROR_MESSAGES[code]);
      } else {
        setFormError(response.response?.data?.message || 'Refund could not be processed.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  useEffect(() => {
    if (errorMsg) {
      const timer = setTimeout(() => setErrorMsg(''), 6000);
      return () => clearTimeout(timer);
    }
  }, [errorMsg]);

  return (
    <main className="min-h-screen bg-slate-50 py-10 px-4 sm:px-6 lg:px-8">
      <div className="max-w-7xl mx-auto">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
          <div>
            <h1 className="text-3xl font-extrabold text-slate-900 mt-2">Payment Refunds Desk</h1>
            <p className="text-sm text-slate-500 mt-1">
              Issue full or partial refunds against captured payments. Refunds never revoke enrollment.
            </p>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row gap-3 mb-6">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search order, payment, student…"
            className="flex-1 rounded-xl border border-slate-300 p-3 text-sm text-slate-900 focus:border-blue-600 outline-none"
          />
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-xl border border-slate-300 p-3 text-sm font-bold text-slate-700 focus:border-blue-600 outline-none"
          >
            {['all', 'paid', 'partially_refunded', 'refunded', 'failed', 'created'].map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </div>

        {errorMsg && <div className="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-700">{errorMsg}</div>}
        {successMsg && (
          <div className="mb-6 rounded-2xl bg-green-50 p-4 text-sm text-green-700 font-bold">✓ {successMsg}</div>
        )}

        <div className="bg-white rounded-3xl ring-1 ring-slate-200 shadow-sm overflow-hidden">
          {loading ? (
            <div className="p-12 text-center text-slate-500">Loading payment ledger…</div>
          ) : payments.length === 0 ? (
            <div className="p-12 text-center text-slate-400 italic">No payments found for the selected filter.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm text-slate-600">
                <thead className="bg-slate-50/80 text-xs font-extrabold uppercase tracking-wider text-slate-400 border-b border-slate-200">
                  <tr>
                    <th className="px-6 py-4">Payment</th>
                    <th className="px-6 py-4">Student / Course</th>
                    <th className="px-6 py-4">Amount</th>
                    <th className="px-6 py-4">Refunded</th>
                    <th className="px-6 py-4">Status</th>
                    <th className="px-6 py-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {payments.map((p) => (
                    <tr key={p.id} className="hover:bg-slate-50/50 transition">
                      <td className="px-6 py-4">
                        <p className="font-mono font-bold text-slate-900 text-xs">{p.payment_id || '—'}</p>
                        <p className="text-xs text-slate-400">{p.provider}</p>
                      </td>
                      <td className="px-6 py-4">
                        <p className="font-bold text-slate-900">{p.user?.name || '—'}</p>
                        <p className="text-xs text-slate-400">{p.course?.title || '—'}</p>
                      </td>
                      <td className="px-6 py-4 font-mono font-bold text-slate-900">
                        {formatPaise(p.amount, p.currency)}
                      </td>
                      <td className="px-6 py-4 font-mono text-slate-700">
                        {formatPaise(p.refunded_amount, p.currency)}
                        <span className="text-slate-400"> / {formatPaise(p.remaining_refundable, p.currency)} left</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="inline-block px-2.5 py-1 rounded-full text-xs font-bold uppercase bg-slate-100 text-slate-700">
                          {p.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button
                          type="button"
                          onClick={() => openDetail(p.id)}
                          className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition shadow"
                        >
                          Refund
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {paymentsMeta && (
            <Pagination meta={paymentsMeta} onPageChange={setPaymentsPage} label="Payment ledger pagination" />
          )}
        </div>
      </div>

      {(selected || loadingDetail || detailError) && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-2xl bg-white rounded-3xl p-8 shadow-2xl space-y-6 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <h3 className="text-xl font-bold text-slate-900">Payment #{selected?.id ?? '…'}</h3>
              <button type="button" onClick={closeDrawer} className="text-slate-400 hover:text-slate-700">
                ✕
              </button>
            </div>

            {loadingDetail && <p className="text-sm text-slate-500">Loading payment details…</p>}
            {detailError && <p className="text-sm text-red-700">{detailError}</p>}

            {selected && phase === 'detail' && (
              <>
                <div className="grid grid-cols-2 gap-3 text-sm">
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Captured</span>
                    <span className="font-bold text-slate-900">{formatPaise(selected.amount, selected.currency)}</span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Remaining refundable</span>
                    <span className="font-bold text-slate-900">
                      {formatPaise(selected.remaining_refundable, selected.currency)}
                    </span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Status</span>
                    <span className="font-bold text-slate-900">{selected.status}</span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Provider</span>
                    <span className="font-mono text-xs text-slate-900">{selected.provider}</span>
                  </div>
                </div>

                <div>
                  <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">Refund history</h4>
                  {selected.refunds.length === 0 ? (
                    <p className="text-sm text-slate-400 italic">No refunds recorded for this payment.</p>
                  ) : (
                    <ul className="space-y-2">
                      {selected.refunds.map((r) => (
                        <li key={r.id} className="p-3 rounded-xl bg-slate-50 border border-slate-100 text-xs">
                          <span className="font-mono font-bold text-slate-900">
                            {formatPaise(r.amount, r.currency)}
                          </span>{' '}
                          <span className="text-slate-500">
                            · {r.status} · {r.source} · {r.provider_refund_id || 'no provider id'}
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>

                {selected.remaining_refundable > 0 ? (
                  <button
                    type="button"
                    onClick={startRefund}
                    className="px-6 py-2.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow"
                  >
                    Initiate Refund
                  </button>
                ) : (
                  <p className="text-sm text-slate-400 italic">Fully refunded — no remaining balance.</p>
                )}
              </>
            )}

            {selected && phase === 'confirm' && (
              <form onSubmit={submitRefund} className="space-y-4">
                <div className="p-4 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900">
                  Refunding{' '}
                  <strong>
                    {amountInput.trim() === ''
                      ? `${formatPaise(selected.remaining_refundable, selected.currency)} (full remaining)`
                      : formatPaise(Math.round(Number(amountInput) * 100), selected.currency)}
                  </strong>{' '}
                  for payment <span className="font-mono">{selected.payment_id}</span>. This cannot be undone from
                  this console. Enrollment access is never revoked by a refund.
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Amount in {selected.currency} (blank = full remaining{' '}
                    {formatPaise(selected.remaining_refundable, selected.currency)})
                  </label>
                  <input
                    type="number"
                    min={0}
                    step="0.01"
                    value={amountInput}
                    onChange={(e) => setAmountInput(e.target.value)}
                    placeholder="e.g. 250.00"
                    className="w-full rounded-xl border border-slate-300 p-3 text-sm font-bold text-slate-900 focus:border-blue-600 outline-none"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Reason (min 10 characters)</label>
                  <textarea
                    rows={3}
                    value={reasonInput}
                    onChange={(e) => setReasonInput(e.target.value)}
                    placeholder="Why is this refund being issued?"
                    className="w-full rounded-xl border border-slate-300 p-3 text-sm text-slate-900 focus:border-blue-600 outline-none"
                    required
                  />
                </div>
                {formError && <p className="text-sm text-red-700">{formError}</p>}
                <div className="flex justify-end gap-3">
                  <button
                    type="button"
                    onClick={() => setPhase('detail')}
                    className="px-5 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl"
                  >
                    Back
                  </button>
                  <button
                    type="submit"
                    disabled={submitting}
                    className="px-6 py-2.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow disabled:opacity-50"
                  >
                    {submitting ? 'Processing…' : 'Confirm Refund ✓'}
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}
    </main>
  );
}
