import { useState } from 'react';
import API from '../../services/api';
import Pagination from '../../components/Pagination';
import { usePagedQuery } from '../../hooks/usePagedQuery';
import { WEBHOOK_EVENTS, type WebhookDelivery, type WebhookSubscription } from '../../types/webhook';

const emptyForm = { target_url: '', secret: '', events: [] as string[], is_active: true };

export default function AdminWebhooks() {
  const [form, setForm] = useState(emptyForm);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [formError, setFormError] = useState('');
  const [successMsg, setSuccessMsg] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [statusFilter, setStatusFilter] = useState('all');
  const [actionError, setActionError] = useState('');
  const [retryingId, setRetryingId] = useState<number | null>(null);

  const {
    items: subscriptions,
    meta: subsMeta,
    loading,
    setPage: setSubsPage,
    reload: reloadSubs,
  } = usePagedQuery<WebhookSubscription>('/admin/webhook-subscriptions', {}, { errorMessage: 'Failed to load webhook subscriptions.' });

  const {
    items: deliveries,
    meta: deliveriesMeta,
    loading: deliveriesLoading,
    setPage: setDeliveriesPage,
    reload: reloadDeliveries,
  } = usePagedQuery<WebhookDelivery>(
    '/admin/webhook-deliveries',
    {
      webhook_subscription_id: selectedId ?? undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
    },
    { errorMessage: 'Failed to load webhook deliveries.' }
  );

  const toggleEvent = (event: string) => {
    setForm((f) => ({
      ...f,
      events: f.events.includes(event) ? f.events.filter((e) => e !== event) : [...f.events, event],
    }));
  };

  const startEdit = (sub: WebhookSubscription) => {
    setEditingId(sub.id);
    setForm({ target_url: sub.target_url, secret: '', events: sub.events, is_active: sub.is_active });
    setFormError('');
    setSuccessMsg('');
  };

  const cancelEdit = () => {
    setEditingId(null);
    setForm(emptyForm);
    setFormError('');
  };

  const submitForm = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setFormError('');
    setSuccessMsg('');

    if (form.events.length === 0) {
      setFormError('Select at least one event.');
      return;
    }
    if (!editingId && form.secret.trim().length < 32) {
      setFormError('Provide a secret of at least 32 characters.');
      return;
    }

    setSubmitting(true);
    try {
      const payload: Record<string, unknown> = {
        target_url: form.target_url.trim(),
        events: form.events,
        is_active: form.is_active,
      };
      if (!editingId || form.secret.trim() !== '') {
        payload.secret = form.secret;
      }
      if (editingId) {
        await API.put(`/admin/webhook-subscriptions/${editingId}`, payload);
        setSuccessMsg('Subscription updated.');
      } else {
        await API.post('/admin/webhook-subscriptions', payload);
        setSuccessMsg('Subscription created. Store the secret securely — it is never shown again.');
      }
      cancelEdit();
      reloadSubs();
    } catch {
      setFormError('Failed to save subscription. Check the URL, secret, and events.');
    } finally {
      setSubmitting(false);
    }
  };

  const deleteSubscription = async (id: number) => {
    if (!window.confirm('Delete this webhook subscription and its delivery history?')) return;
    setActionError('');
    try {
      await API.delete(`/admin/webhook-subscriptions/${id}`);
      if (selectedId === id) setSelectedId(null);
      reloadSubs();
      reloadDeliveries();
    } catch {
      setActionError('Failed to delete subscription.');
    }
  };

  const retryDelivery = async (id: number) => {
    setRetryingId(id);
    setActionError('');
    try {
      await API.post(`/admin/webhook-deliveries/${id}/retry`);
      reloadDeliveries();
    } catch {
      setActionError('Retry failed. Only failed or dead deliveries can be retried.');
    } finally {
      setRetryingId(null);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-white">Outbound Webhooks</h1>
        <p className="text-sm text-slate-400">Subscribe external HTTPS endpoints to payment, enrollment, and certificate events. Deliveries are signed, retried, and audited.</p>
      </div>

      {successMsg !== '' && <p className="text-sm text-emerald-400">{successMsg}</p>}
      {actionError !== '' && <p className="text-sm text-red-400">{actionError}</p>}

      <form onSubmit={submitForm} className="space-y-3 rounded border border-slate-800 bg-slate-900 p-4">
        <h2 className="text-sm font-semibold text-white">{editingId ? 'Edit subscription' : 'New subscription'}</h2>
        <input
          className="w-full rounded border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
          placeholder="Target URL (https://…)"
          value={form.target_url}
          onChange={(e) => setForm({ ...form, target_url: e.target.value })}
        />
        <input
          className="w-full rounded border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
          placeholder={editingId ? 'Secret (leave blank to keep current)' : 'Secret (min 32 characters)'}
          value={form.secret}
          onChange={(e) => setForm({ ...form, secret: e.target.value })}
        />
        <div className="flex flex-wrap gap-2">
          {WEBHOOK_EVENTS.map((event) => (
            <label key={event} className="flex items-center gap-1 text-xs text-slate-300">
              <input type="checkbox" checked={form.events.includes(event)} onChange={() => toggleEvent(event)} />
              {event}
            </label>
          ))}
        </div>
        <label className="flex items-center gap-1 text-xs text-slate-300">
          <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
          Active
        </label>
        {formError !== '' && <p className="text-sm text-red-400">{formError}</p>}
        <div className="flex gap-2">
          <button type="submit" disabled={submitting} className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white disabled:opacity-50">
            {editingId ? 'Save changes' : 'Create subscription'}
          </button>
          {editingId && (
            <button type="button" onClick={cancelEdit} className="rounded border border-slate-700 px-3 py-1.5 text-sm text-slate-300">
              Cancel
            </button>
          )}
        </div>
      </form>

      <div className="rounded border border-slate-800 bg-slate-900 p-4">
        <h2 className="mb-2 text-sm font-semibold text-white">Subscriptions</h2>
        {loading ? (
          <p className="text-sm text-slate-400">Loading…</p>
        ) : (
          <ul className="space-y-2">
            {subscriptions.map((sub) => (
              <li key={sub.id} className="flex flex-wrap items-center gap-2 rounded border border-slate-800 px-3 py-2 text-xs text-slate-300">
                <span className="font-mono">{sub.target_url}</span>
                <span>{sub.events.join(', ')}</span>
                <span>{sub.is_active ? 'active' : 'inactive'}</span>
                {sub.failed_deliveries > 0 && <span className="text-amber-400">{sub.failed_deliveries} failed</span>}
                <button onClick={() => setSelectedId(sub.id)} className="text-blue-400 underline">deliveries</button>
                <button onClick={() => startEdit(sub)} className="text-blue-400 underline">edit</button>
                <button onClick={() => deleteSubscription(sub.id)} className="text-red-400 underline">delete</button>
              </li>
            ))}
          </ul>
        )}
        {subsMeta && <Pagination meta={subsMeta} onPageChange={setSubsPage} />}
      </div>

      <div className="rounded border border-slate-800 bg-slate-900 p-4">
        <div className="mb-2 flex items-center gap-2">
          <h2 className="text-sm font-semibold text-white">Deliveries{selectedId ? ` for subscription ${selectedId}` : ''}</h2>
          {selectedId && (
            <button onClick={() => setSelectedId(null)} className="text-xs text-blue-400 underline">show all</button>
          )}
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="ml-auto rounded border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-white">
            {['all', 'pending', 'delivered', 'failed', 'dead'].map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
        </div>
        {deliveriesLoading ? (
          <p className="text-sm text-slate-400">Loading…</p>
        ) : (
          <ul className="space-y-2">
            {deliveries.map((d) => (
              <li key={d.id} className="flex flex-wrap items-center gap-2 rounded border border-slate-800 px-3 py-2 text-xs text-slate-300">
                <span>#{d.id}</span>
                <span className="font-mono">{d.event}</span>
                <span>{d.status}</span>
                <span>attempts {d.attempts}</span>
                {d.delivered_at && <span>delivered {d.delivered_at}</span>}
                {d.next_retry_at && <span>retry {d.next_retry_at}</span>}
                {d.last_error && <span className="text-red-400">{d.last_error}</span>}
                {(d.status === 'failed' || d.status === 'dead') && (
                  <button onClick={() => retryDelivery(d.id)} disabled={retryingId === d.id} className="text-blue-400 underline disabled:opacity-50">
                    retry
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
        {deliveriesMeta && <Pagination meta={deliveriesMeta} onPageChange={setDeliveriesPage} />}
      </div>
    </div>
  );
}
