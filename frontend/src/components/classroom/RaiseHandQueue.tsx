import type { ClassroomPermissionRequest } from '../../types/classroom';

interface RaiseHandQueueProps {
  requests: ClassroomPermissionRequest[];
  onResolve: (requestId: number, action: 'approve' | 'deny') => void;
  loadingId?: number | null;
}

export default function RaiseHandQueue({
  requests,
  onResolve,
  loadingId,
}: RaiseHandQueueProps) {
  if (requests.length === 0) return null;

  return (
    <div className="bg-amber-950/40 border border-amber-500/40 rounded-2xl p-3.5 space-y-2.5 backdrop-blur-md shadow-lg animate-in fade-in">
      <div className="flex items-center justify-between">
        <h4 className="text-xs font-black text-amber-300 uppercase tracking-wider flex items-center gap-1.5">
          <span>✋</span>
          <span>Speaking Requests Queue</span>
        </h4>
        <span className="px-2 py-0.5 rounded-full bg-amber-500 text-slate-950 text-[10px] font-black">
          {requests.length} Pending
        </span>
      </div>

      <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
        {requests.map((req) => (
          <div
            key={req.id}
            className="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 flex items-center justify-between gap-2 text-xs"
          >
            <div className="flex items-center gap-2 min-w-0">
              <div className="w-6 h-6 rounded-lg bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-amber-300 font-bold text-[11px] shrink-0">
                ✋
              </div>
              <div className="min-w-0">
                <span className="font-bold text-white block truncate text-[11px]">
                  {req.user?.name || `Student #${req.user_id}`}
                </span>
                <span className="text-[10px] text-slate-400 block truncate">
                  {req.user?.email || 'Student Participant'}
                </span>
              </div>
            </div>

            <div className="flex items-center gap-1.5 shrink-0">
              <button
                type="button"
                onClick={() => onResolve(req.id, 'approve')}
                disabled={loadingId === req.id}
                title="Approve speaking request and unlock microphone"
                className="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] transition shadow-xs disabled:opacity-50"
              >
                {loadingId === req.id ? '...' : '✓ Allow'}
              </button>
              <button
                type="button"
                onClick={() => onResolve(req.id, 'deny')}
                disabled={loadingId === req.id}
                title="Deny request and keep student muted"
                className="px-2 py-1 rounded-lg bg-slate-800 hover:bg-red-950 hover:text-red-300 text-slate-400 font-bold text-[11px] transition border border-slate-700 disabled:opacity-50"
              >
                ✕ Deny
              </button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
