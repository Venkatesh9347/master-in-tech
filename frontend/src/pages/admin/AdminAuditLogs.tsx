import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

interface AuditLogEntry {
  id: number
  user_name: string | null
  action: string
  auditable_type: string | null
  auditable_id: number | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  created_at: string
  user?: {
    id: number
    name: string
    email: string
  } | null
}

export default function AdminAuditLogs() {
  const [logs, setLogs] = useState<AuditLogEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [selectedLog, setSelectedLog] = useState<AuditLogEntry | null>(null)
  const [actionFilter, setActionFilter] = useState('')

  const loadLogs = useCallback(() => {
    setLoading(true)
    setError(null)
    const params = actionFilter ? { action: actionFilter } : {}
    API.get<AuditLogEntry[] | { data: AuditLogEntry[] | { data: AuditLogEntry[] } }>('/admin/audit-logs', { params })
      .then((res) => {
        let list: AuditLogEntry[] = []
        if (Array.isArray(res.data)) {
          list = res.data
        } else if (Array.isArray(res.data?.data)) {
          list = res.data.data
        } else if (res.data?.data && typeof res.data.data === 'object' && 'data' in res.data.data && Array.isArray((res.data.data as { data: AuditLogEntry[] }).data)) {
          list = (res.data.data as { data: AuditLogEntry[] }).data
        }
        setLogs(list)
      })
      .catch((err: unknown) => {
        const response = err as { response?: { data?: { message?: string } } }
        setError(response.response?.data?.message || 'Failed to fetch audit logs. Please try again.')
      })
      .finally(() => setLoading(false))
  }, [actionFilter])

  useEffect(() => {
    loadLogs()
  }, [loadLogs])

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">System Audit & Change Trail</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Real-time audit log tracking batches, class sessions, student enrollments, content publications, and settings changes.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <select
            value={actionFilter}
            onChange={(e) => setActionFilter(e.target.value)}
            className="px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-xs text-white outline-none"
          >
            <option value="">All Change Actions</option>
            <option value="created_batch">Created Batch</option>
            <option value="updated_batch">Updated Batch</option>
            <option value="deleted_batch">Deleted Batch</option>
            <option value="assigned_batch_student">Assigned Student to Batch</option>
            <option value="transferred_batch_student">Transferred Batch Student</option>
            <option value="discontinued_batch_student">Discontinued Batch Student</option>
            <option value="rejoined_batch_student">Rejoined Batch Student</option>
            <option value="removed_batch_student">Removed Student from Batch</option>
            <option value="created_class_session">Created Class Session</option>
            <option value="updated_class_session">Updated Class Session</option>
            <option value="cancelled_class_session">Cancelled Class Session</option>
            <option value="deleted_class_session">Deleted Class Session</option>
            <option value="created_enrollment">Created Enrollment</option>
            <option value="updated_enrollment">Updated Enrollment</option>
            <option value="deleted_enrollment">Deleted Enrollment</option>
            <option value="created_course">Created Course</option>
            <option value="updated_course">Updated Course</option>
            <option value="deleted_course">Deleted Course</option>
            <option value="updated_home_section">Updated Home CMS</option>
            <option value="updated_website_settings">Updated Settings</option>
            <option value="created_instructor">Created Instructor</option>
          </select>
          <button
            type="button"
            onClick={loadLogs}
            className="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-bold text-slate-300 transition"
          >
            🔄 Refresh
          </button>
        </div>
      </div>

      {/* Logs Table */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8">
        {loading ? (
          <div className="py-12 text-center text-slate-400 space-y-3">
            <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin mx-auto" />
            <p className="text-xs font-semibold">Loading system audit logs...</p>
          </div>
        ) : error ? (
          <div className="py-10 text-center space-y-3">
            <span className="text-3xl block">⚠️</span>
            <p className="text-xs font-bold text-rose-400">{error}</p>
            <button
              type="button"
              onClick={loadLogs}
              className="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs transition shadow-sm"
            >
              ↻ Retry Loading Audit Logs
            </button>
          </div>
        ) : logs.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">📋</span>
            <p className="font-bold text-slate-300">No audit logs found</p>
            <p className="text-slate-500 mt-1">Actions performed in the system will automatically appear in this audit log.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                  <th className="py-3 px-4">Timestamp</th>
                  <th className="py-3 px-4">Administrator</th>
                  <th className="py-3 px-4">Action</th>
                  <th className="py-3 px-4">Target Model</th>
                  <th className="py-3 px-4">IP Address</th>
                  <th className="py-3 px-4 text-right">Details</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60">
                {logs.map((log) => (
                  <tr key={log.id} className="hover:bg-slate-900/60 transition">
                    <td className="py-4 px-4 font-mono text-[11px] text-slate-400">
                      {new Date(log.created_at).toLocaleString()}
                    </td>
                    <td className="py-4 px-4">
                      <span className="font-bold text-white">{log.user_name || log.user?.name || 'System'}</span>
                    </td>
                    <td className="py-4 px-4">
                      <span className="px-2 py-0.5 rounded-md bg-purple-950 text-purple-300 border border-purple-800 text-[10px] font-bold">
                        {log.action}
                      </span>
                    </td>
                    <td className="py-4 px-4 font-mono text-[11px] text-slate-400">
                      {log.auditable_type ? log.auditable_type.split('\\').pop() : '—'} #{log.auditable_id || '—'}
                    </td>
                    <td className="py-4 px-4 font-mono text-[11px] text-slate-500">
                      {log.ip_address || '127.0.0.1'}
                    </td>
                    <td className="py-4 px-4 text-right">
                      <button
                        type="button"
                        onClick={() => setSelectedLog(log)}
                        className="px-2.5 py-1 rounded-lg bg-slate-900 text-purple-400 hover:text-white border border-slate-700 font-bold text-[11px] transition"
                      >
                        Inspect Payload
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* INSPECT MODAL */}
      {selectedLog && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white max-h-[85vh] overflow-y-auto text-xs">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h3 className="font-bold text-white text-base">Audit Log Payload #{selectedLog.id}</h3>
              <button
                type="button"
                onClick={() => setSelectedLog(null)}
                className="text-slate-400 hover:text-white font-bold"
              >
                ✕
              </button>
            </div>

            <div className="space-y-3">
              <div>
                <span className="text-slate-400 font-bold uppercase text-[10px]">Action:</span>
                <p className="font-bold text-purple-400 text-sm">{selectedLog.action}</p>
              </div>

              {selectedLog.old_values && (
                <div>
                  <span className="text-slate-400 font-bold uppercase text-[10px]">Previous Values:</span>
                  <pre className="p-3 bg-slate-950 rounded-xl font-mono text-[11px] text-amber-300 border border-slate-800 overflow-x-auto mt-1">
                    {JSON.stringify(selectedLog.old_values, null, 2)}
                  </pre>
                </div>
              )}

              {selectedLog.new_values && (
                <div>
                  <span className="text-slate-400 font-bold uppercase text-[10px]">Updated Values:</span>
                  <pre className="p-3 bg-slate-950 rounded-xl font-mono text-[11px] text-emerald-300 border border-slate-800 overflow-x-auto mt-1">
                    {JSON.stringify(selectedLog.new_values, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
