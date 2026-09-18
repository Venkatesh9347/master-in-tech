import { useEffect, useState } from 'react';
import API from '../../services/api';
import Pagination from '../../components/Pagination';
import { usePagedQuery } from '../../hooks/usePagedQuery';
import {
  CERTIFICATE_ERROR_MESSAGES,
  CERTIFICATE_REASON_MAX_LENGTH,
  CERTIFICATE_REASON_MIN_LENGTH,
  CERTIFICATE_STATUS_FILTERS,
  type CertificateDetail,
  type CertificateListItem,
  type CertificateRevocation,
} from '../../types/certificate';

type Phase = 'detail' | 'confirm';

export default function AdminCertificates() {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [selected, setSelected] = useState<CertificateDetail | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [phase, setPhase] = useState<Phase>('detail');
  const [reasonInput, setReasonInput] = useState('');
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [successMsg, setSuccessMsg] = useState('');
  const [errorMsg, setErrorMsg] = useState('');

  // Certificate registry: server-paginated; filter/search changes reset to page 1.
  const {
    items: certificates,
    meta: certificatesMeta,
    loading,
    setPage: setCertificatesPage,
    reload: reloadCertificates,
  } = usePagedQuery<CertificateListItem>(
    '/admin/certificates',
    {
      search: search.trim() || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
    },
    {
      errorMessage: CERTIFICATE_ERROR_MESSAGES.load_failed,
    }
  );

  const closeDrawer = () => {
    setSelected(null);
    setPhase('detail');
    setReasonInput('');
    setFormError('');
    setDetailError('');
  };

  const openDetail = async (id: number) => {
    closeDrawer();
    setLoadingDetail(true);
    try {
      const res = await API.get<{ certificate: CertificateDetail }>(`/admin/certificates/${id}`);
      setSelected(res.data.certificate);
    } catch {
      setDetailError(CERTIFICATE_ERROR_MESSAGES.detail_failed);
    } finally {
      setLoadingDetail(false);
    }
  };

  const startRevoke = () => {
    setFormError('');
    setSuccessMsg('');
    setErrorMsg('');
    setPhase('confirm');
  };

  /** Merge the revoke response payload into the open detail row. */
  const applyRevocation = (revocation: CertificateRevocation) => {
    setSelected((prev) =>
      prev === null
        ? prev
        : {
            ...prev,
            status: revocation.status,
            revoked_at: revocation.revoked_at,
            revoked_by: revocation.revoked_by,
            revocation_reason: revocation.revocation_reason,
          }
    );
  };

  const submitRevoke = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selected || submitting) return;

    const trimmedReason = reasonInput.trim();
    if (trimmedReason.length < CERTIFICATE_REASON_MIN_LENGTH) {
      setFormError(`Provide a reason of at least ${CERTIFICATE_REASON_MIN_LENGTH} characters.`);
      return;
    }
    if (trimmedReason.length > CERTIFICATE_REASON_MAX_LENGTH) {
      setFormError(`Reason must be no more than ${CERTIFICATE_REASON_MAX_LENGTH} characters.`);
      return;
    }

    setSubmitting(true);
    setFormError('');
    try {
      // {certificate} is the numeric database id, never the certificate code.
      const res = await API.post<{ message?: string; certificate: CertificateRevocation }>(
        `/admin/certificates/${selected.id}/revoke`,
        { reason: trimmedReason }
      );
      const revocation = res.data.certificate;
      applyRevocation(revocation);
      if (typeof res.data.message === 'string' && res.data.message.toLowerCase().includes('already')) {
        setSuccessMsg(
          `Certificate ${revocation.certificate_code} was already revoked. Original revocation preserved.`
        );
      } else {
        setSuccessMsg(`Certificate ${revocation.certificate_code} revoked.`);
      }
      setPhase('detail');
      setReasonInput('');
      reloadCertificates();
    } catch (err: unknown) {
      const response = err as {
        response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } };
      };
      const status = response.response?.status;
      const fieldErrors = response.response?.data?.errors;
      if (status === 404) {
        setFormError(CERTIFICATE_ERROR_MESSAGES.certificate_not_found);
      } else if (status === 403) {
        setFormError(CERTIFICATE_ERROR_MESSAGES.permission_denied);
      } else if (status === 401) {
        setFormError(CERTIFICATE_ERROR_MESSAGES.session_expired);
      } else if (fieldErrors) {
        const first = Object.values(fieldErrors).flat()[0];
        setFormError(typeof first === 'string' ? first : CERTIFICATE_ERROR_MESSAGES.validation_failed);
      } else {
        setFormError(response.response?.data?.message || CERTIFICATE_ERROR_MESSAGES.revoke_failed);
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
            <h1 className="text-3xl font-extrabold text-slate-900 mt-2">Certificate Revocation Desk</h1>
            <p className="text-sm text-slate-500 mt-1">
              Look up issued certificates and revoke compromised or invalid ones. Revocation is
              permanent: verification reports revoked, downloads are blocked, and mock-interview
              eligibility no longer counts the certificate.
            </p>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row gap-3 mb-6">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search code, student, email, course…"
            className="flex-1 rounded-xl border border-slate-300 p-3 text-sm text-slate-900 focus:border-blue-600 outline-none"
          />
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-xl border border-slate-300 p-3 text-sm font-bold text-slate-700 focus:border-blue-600 outline-none"
          >
            {CERTIFICATE_STATUS_FILTERS.map((s) => (
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
            <div className="p-12 text-center text-slate-500">Loading certificate registry…</div>
          ) : certificates.length === 0 ? (
            <div className="p-12 text-center text-slate-400 italic">
              No certificates found for the selected filter.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm text-slate-600">
                <thead className="bg-slate-50/80 text-xs font-extrabold uppercase tracking-wider text-slate-400 border-b border-slate-200">
                  <tr>
                    <th className="px-6 py-4">Certificate</th>
                    <th className="px-6 py-4">Student / Course</th>
                    <th className="px-6 py-4">Issued</th>
                    <th className="px-6 py-4">Status</th>
                    <th className="px-6 py-4">Revoked</th>
                    <th className="px-6 py-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {certificates.map((c) => (
                    <tr key={c.id} className="hover:bg-slate-50/50 transition">
                      <td className="px-6 py-4">
                        <p className="font-mono font-bold text-slate-900 text-xs">{c.certificate_code}</p>
                        <p className="text-xs text-slate-400">#{c.id}</p>
                      </td>
                      <td className="px-6 py-4">
                        <p className="font-bold text-slate-900">{c.user?.name || '—'}</p>
                        <p className="text-xs text-slate-400">{c.course?.title || '—'}</p>
                      </td>
                      <td className="px-6 py-4 text-xs text-slate-700">
                        {c.issued_at ? new Date(c.issued_at).toLocaleDateString() : '—'}
                      </td>
                      <td className="px-6 py-4">
                        <span className="inline-block px-2.5 py-1 rounded-full text-xs font-bold uppercase bg-slate-100 text-slate-700">
                          {c.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-xs text-slate-700">
                        {c.revoked_at ? new Date(c.revoked_at).toLocaleDateString() : '—'}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex justify-end gap-2">
                          <button
                            type="button"
                            onClick={() => openDetail(c.id)}
                            className="px-4 py-2 rounded-xl text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 transition"
                          >
                            View
                          </button>
                          {c.status !== 'revoked' && (
                            <button
                              type="button"
                              onClick={() => openDetail(c.id)}
                              className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-700 transition shadow"
                            >
                              Revoke
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {certificatesMeta && (
            <Pagination
              meta={certificatesMeta}
              onPageChange={setCertificatesPage}
              label="Certificate registry pagination"
            />
          )}
        </div>
      </div>

      {(selected || loadingDetail || detailError) && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-2xl bg-white rounded-3xl p-8 shadow-2xl space-y-6 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <h3 className="text-xl font-bold text-slate-900">
                Certificate {selected?.certificate_code ?? '…'}
              </h3>
              <button type="button" onClick={closeDrawer} className="text-slate-400 hover:text-slate-700">
                ✕
              </button>
            </div>

            {loadingDetail && <p className="text-sm text-slate-500">Loading certificate details…</p>}
            {detailError && <p className="text-sm text-red-700">{detailError}</p>}

            {selected && phase === 'detail' && (
              <>
                <div className="grid grid-cols-2 gap-3 text-sm">
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Student</span>
                    <span className="font-bold text-slate-900">{selected.user?.name || '—'}</span>
                    <span className="text-slate-500 block text-xs">{selected.user?.email || ''}</span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Course</span>
                    <span className="font-bold text-slate-900">{selected.course?.title || '—'}</span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Status</span>
                    <span className="font-bold text-slate-900">{selected.status}</span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Issued</span>
                    <span className="font-bold text-slate-900">
                      {selected.issued_at ? new Date(selected.issued_at).toLocaleDateString() : '—'}
                    </span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Revoked at</span>
                    <span className="font-bold text-slate-900">
                      {selected.revoked_at ? new Date(selected.revoked_at).toLocaleString() : '—'}
                    </span>
                  </div>
                  <div className="p-3 rounded-xl bg-slate-50">
                    <span className="text-slate-400 block text-xs font-semibold">Revoked by</span>
                    <span className="font-bold text-slate-900">
                      {selected.revoked_by_user?.name || (selected.revoked_by ? `#${selected.revoked_by}` : '—')}
                    </span>
                  </div>
                </div>

                <div className="p-3 rounded-xl bg-slate-50 text-sm">
                  <span className="text-slate-400 block text-xs font-semibold mb-1">Revocation reason</span>
                  <span className="text-slate-900">{selected.revocation_reason || '—'}</span>
                </div>

                {selected.status !== 'revoked' ? (
                  <button
                    type="button"
                    onClick={startRevoke}
                    className="px-6 py-2.5 text-xs font-bold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow"
                  >
                    Revoke Certificate
                  </button>
                ) : (
                  <p className="text-sm text-slate-400 italic">
                    Already revoked — revocation is permanent and cannot be undone from this console.
                  </p>
                )}
              </>
            )}

            {selected && phase === 'confirm' && (
              <form onSubmit={submitRevoke} className="space-y-4">
                <div className="p-4 rounded-xl bg-red-50 border border-red-200 text-xs text-red-900">
                  Revoking certificate <span className="font-mono font-bold">{selected.certificate_code}</span>{' '}
                  issued to <strong>{selected.user?.name || 'the student'}</strong> for{' '}
                  <strong>{selected.course?.title || 'the course'}</strong>. This cannot be undone from
                  this console. Verification will report the certificate as revoked, PDF download will
                  be blocked, and mock-interview eligibility will no longer count it.
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Reason ({CERTIFICATE_REASON_MIN_LENGTH}–{CERTIFICATE_REASON_MAX_LENGTH} characters)
                  </label>
                  <textarea
                    rows={3}
                    value={reasonInput}
                    onChange={(e) => setReasonInput(e.target.value)}
                    placeholder="Why is this certificate being revoked?"
                    className="w-full rounded-xl border border-slate-300 p-3 text-sm text-slate-900 focus:border-red-600 outline-none"
                    required
                  />
                  <p className="text-xs text-slate-400 mt-1">
                    {reasonInput.trim().length}/{CERTIFICATE_REASON_MAX_LENGTH}
                  </p>
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
                    className="px-6 py-2.5 text-xs font-bold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow disabled:opacity-50"
                  >
                    {submitting ? 'Revoking…' : 'Confirm Revocation ✓'}
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
