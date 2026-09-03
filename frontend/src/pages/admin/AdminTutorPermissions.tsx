import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

interface TutorUser {
  id: number
  name: string
  email: string
  role: string
  status: string
  avatar?: string | null
  expertise?: string | null
  taught_courses_count: number
  permissions: Record<string, boolean>
}

const PERMISSION_DEFINITIONS = [
  { key: 'view_assigned_courses', label: 'View Assigned Courses', desc: 'Can view assigned courses, curriculum and schedules' },
  { key: 'view_students', label: 'View Students', desc: 'Can view student roster for assigned courses' },
  { key: 'upload_materials', label: 'Upload Materials', desc: 'Can upload lecture slides, documents and handouts' },
  { key: 'manage_materials', label: 'Manage Materials', desc: 'Can edit and delete own uploaded course handouts' },
  { key: 'create_quizzes', label: 'Create Quizzes', desc: 'Can author new module quizzes and question sets' },
  { key: 'edit_quizzes', label: 'Edit Quizzes', desc: 'Can modify existing course quiz questions' },
  { key: 'delete_quizzes', label: 'Delete Quizzes', desc: 'Can remove quizzes from assigned courses' },
  { key: 'publish_quizzes', label: 'Publish Quizzes', desc: 'Can publish/unpublish quizzes to students' },
  { key: 'view_quiz_results', label: 'View Quiz Results', desc: 'Can view student scores and submission analytics' },
]

export default function AdminTutorPermissions() {
  const [tutors, setTutors] = useState<TutorUser[]>([])
  const [selectedTutorId, setSelectedTutorId] = useState<number | null>(null)
  const [permsState, setPermsState] = useState<Record<string, boolean>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const fetchTutors = useCallback(async (targetId?: number | null) => {
    setLoading(true)
    setError('')
    try {
      const res = await API.get<TutorUser[]>('/admin/tutors')
      const list = Array.isArray(res.data) ? res.data : []
      setTutors(list)

      if (list.length > 0) {
        setSelectedTutorId((prevId) => {
          const activeId = targetId !== undefined ? targetId : prevId
          const match = list.find((t) => t.id === activeId) || list[0]
          setPermsState(match.permissions || {})
          return match.id
        })
      } else {
        setSelectedTutorId(null)
        setPermsState({})
      }
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setError(resp.response?.data?.message || 'Unable to load faculty roster. Please check network connection and retry.')
      setTutors([])
      setSelectedTutorId(null)
      setPermsState({})
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchTutors()
  }, [fetchTutors])

  const selectedTutor = tutors.find((t) => t.id === selectedTutorId) || null

  const handleSelectTutor = (tutor: TutorUser) => {
    setSelectedTutorId(tutor.id)
    setPermsState(tutor.permissions || {})
    setError('')
    setSuccess('')
  }

  const handleTogglePermission = (key: string) => {
    setPermsState((prev) => ({
      ...prev,
      [key]: !prev[key],
    }))
  }

  const handleSavePermissions = async () => {
    if (!selectedTutor) return
    setSaving(true)
    setError('')
    setSuccess('')

    try {
      await API.put(`/admin/tutors/${selectedTutor.id}/permissions`, {
        permissions: permsState,
      })
      setSuccess(`Permissions for ${selectedTutor.name} updated successfully.`)
      // Optimistically update current tutor in list
      setTutors((prev) =>
        prev.map((t) =>
          t.id === selectedTutor.id ? { ...t, permissions: { ...t.permissions, ...permsState } } : t
        )
      )
      setTimeout(() => setSuccess(''), 5000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to update permissions.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Faculty & Tutor Permissions 🛡️</h1>
          <p className="text-xs text-slate-400 mt-1">
            Configure granular administrative privileges for instructors, materials, and quizzes.
          </p>
        </div>
        <button
          type="button"
          onClick={() => fetchTutors(selectedTutorId)}
          disabled={loading}
          className="px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-white hover:bg-slate-800 text-xs font-semibold transition flex items-center gap-1.5 self-start sm:self-auto"
        >
          <span>🔄</span> {loading ? 'Refreshing...' : 'Refresh Roster'}
        </button>
      </div>

      {success && (
        <div className="p-4 rounded-2xl bg-emerald-950/80 border border-emerald-500/30 text-emerald-300 text-xs font-semibold flex items-center justify-between">
          <span>✓ {success}</span>
          <button type="button" onClick={() => setSuccess('')} className="text-emerald-400 font-bold">✕</button>
        </div>
      )}

      {error && (
        <div className="p-4 rounded-2xl bg-rose-950/80 border border-rose-500/30 text-rose-300 text-xs font-semibold flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span>⚠️</span>
            <span>{error}</span>
            <button
              type="button"
              onClick={() => fetchTutors(selectedTutorId)}
              className="ml-3 underline hover:text-white font-bold"
            >
              Retry
            </button>
          </div>
          <button type="button" onClick={() => setError('')} className="text-rose-400 font-bold">✕</button>
        </div>
      )}

      {loading ? (
        <div className="py-20 text-center text-slate-500 space-y-3">
          <div className="w-10 h-10 border-2 border-purple-600/20 border-t-purple-600 rounded-full animate-spin mx-auto" />
          <p className="text-xs font-semibold">Loading faculty roster...</p>
        </div>
      ) : tutors.length === 0 ? (
        <div className="p-12 rounded-3xl bg-slate-900 border border-slate-800 text-center space-y-3">
          <span className="text-4xl">👨‍🏫</span>
          <h2 className="text-base font-bold text-white">No tutors or faculty found</h2>
          <p className="text-xs text-slate-400">Add faculty accounts in Users management to assign permissions.</p>
          <button
            type="button"
            onClick={() => fetchTutors()}
            className="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold transition inline-block"
          >
            Retry Loading
          </button>
        </div>
      ) : (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Tutors Column */}
            <div className="bg-slate-900 p-5 rounded-3xl border border-slate-800 space-y-3">
              <h2 className="text-xs font-extrabold uppercase text-slate-400 px-2">
                Faculty Members ({tutors.length})
              </h2>

              <div className="space-y-2 max-h-[550px] overflow-y-auto pr-1">
                {tutors.map((t) => {
                  const isSelected = selectedTutor?.id === t.id
                  return (
                    <button
                      key={t.id}
                      type="button"
                      onClick={() => handleSelectTutor(t)}
                      className={`w-full p-3.5 rounded-2xl text-left transition flex items-center justify-between gap-3 border ${
                        isSelected
                          ? 'bg-purple-600/20 border-purple-500 text-white shadow-sm'
                          : 'bg-slate-950/60 border-slate-800 text-slate-300 hover:bg-slate-800/80'
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-full bg-purple-600 flex items-center justify-center font-bold text-white text-xs shrink-0 shadow-xs">
                          {t.name.charAt(0)}
                        </div>
                        <div className="truncate">
                          <p className="font-bold text-xs truncate">{t.name}</p>
                          <p className="text-[10px] text-slate-400 truncate">{t.email}</p>
                        </div>
                      </div>

                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-800 text-slate-300 border border-slate-700 shrink-0">
                        {t.taught_courses_count} Courses
                      </span>
                    </button>
                  )
                })}
              </div>
            </div>

            {/* Permissions Matrix Column */}
            <div className="lg:col-span-2 bg-slate-900 p-6 sm:p-8 rounded-3xl border border-slate-800 space-y-6">
              {selectedTutor ? (
                <>
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-4">
                    <div>
                      <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30">
                        Faculty Permission Matrix
                      </span>
                      <h2 className="text-lg font-black text-white mt-1">
                        {selectedTutor.name}
                      </h2>
                      <p className="text-xs text-slate-400">{selectedTutor.email}</p>
                    </div>

                    <button
                      type="button"
                      onClick={handleSavePermissions}
                      disabled={saving}
                      className="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 active:bg-purple-700 text-white font-extrabold text-xs transition shadow-md shadow-purple-600/30 flex items-center justify-center gap-2 shrink-0"
                    >
                      <span>💾</span> {saving ? 'Saving...' : 'Save Permissions'}
                    </button>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    {PERMISSION_DEFINITIONS.map((p) => {
                      const isChecked = Boolean(permsState[p.key])
                      return (
                        <label
                          key={p.key}
                          className={`p-4 rounded-2xl border transition cursor-pointer flex items-start gap-3 select-none ${
                            isChecked
                              ? 'bg-purple-950/30 border-purple-500/50'
                              : 'bg-slate-950/60 border-slate-800/80 hover:bg-slate-800/40'
                          }`}
                        >
                          <input
                            type="checkbox"
                            checked={isChecked}
                            onChange={() => handleTogglePermission(p.key)}
                            className="mt-0.5 w-4 h-4 rounded text-purple-600 focus:ring-purple-500 border-slate-700 bg-slate-900"
                          />
                          <div className="space-y-0.5">
                            <p className="font-bold text-xs text-white">{p.label}</p>
                            <p className="text-[11px] text-slate-400 leading-snug">{p.desc}</p>
                          </div>
                        </label>
                      )
                    })}
                  </div>
                </>
              ) : (
                <div className="py-20 text-center text-slate-500 text-xs">
                  Select a faculty member from the list to view and configure their permissions.
                </div>
              )}
            </div>
          </div>
        )}
      </div>
  )
}
