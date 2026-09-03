import { useState, useEffect, useCallback } from 'react'
import { useParams, Link } from 'react-router-dom'
import API from '../../services/api'
import LiveClassList from '../../components/live/LiveClassList'
import type { LiveClass, LiveClassAttendance } from '../../types/liveClass'
import type { ClassMaterial } from '../../types/classSession'

export default function TutorLiveClasses() {
  const { courseId } = useParams<{ courseId: string }>()

  const [classes, setClasses] = useState<LiveClass[]>([])
  const [courseTitle, setCourseTitle] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  // Attendance Sheet Modal State
  const [attendanceModalClass, setAttendanceModalClass] = useState<LiveClass | null>(null)
  const [attendanceList, setAttendanceList] = useState<LiveClassAttendance[]>([]);
  const [loadingAttendance, setLoadingAttendance] = useState(false)

  // Upload Material Modal State
  const [materialModalClass, setMaterialModalClass] = useState<LiveClass | null>(null)
  const [materialTitle, setMaterialTitle] = useState('')
  const [materialFile, setMaterialFile] = useState<File | null>(null)
  const [uploadingMaterial, setUploadingMaterial] = useState(false)

  const fetchLiveClasses = useCallback(async () => {
    if (!courseId) return
    setLoading(true)
    try {
      const res = await API.get<{
        course: { id: number; title: string }
        classes: LiveClass[]
      }>(`/courses/${courseId}/live-classes`)
      setClasses(res.data.classes || [])
      setCourseTitle(res.data.course?.title || '')
    } catch {
      setError('Unable to load live classes for this course.')
    } finally {
      setLoading(false)
    }
  }, [courseId])

  useEffect(() => {
    fetchLiveClasses()
  }, [fetchLiveClasses])

  const handleViewAttendance = async (liveClass: LiveClass) => {
    setAttendanceModalClass(liveClass)
    setLoadingAttendance(true)
    try {
      const res = await API.get<{ attendances: LiveClassAttendance[] }>(
        `/tutor/live-classes/${liveClass.id}/attendance`
      )
      setAttendanceList(res.data.attendances || [])
    } catch {
      // Ignore
    } finally {
      setLoadingAttendance(false)
    }
  }

  const handleUploadMaterialSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!materialModalClass || !materialFile || !materialTitle.trim()) return

    setUploadingMaterial(true)
    const formData = new FormData()
    formData.append('title', materialTitle.trim())
    formData.append('file', materialFile)

    try {
      await API.post<{ material: ClassMaterial }>(
        `/tutor/class-sessions/${materialModalClass.id}/materials`,
        formData,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      )
      setSuccess('Class material uploaded successfully!')
      setMaterialModalClass(null)
      setMaterialTitle('')
      setMaterialFile(null)
      setTimeout(() => setSuccess(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to upload material.')
    } finally {
      setUploadingMaterial(false)
    }
  }

  return (
    <div className="space-y-6">
      {/* Header Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-5">
        <div>
          <div className="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-1">
            <Link to="/tutor/courses" className="hover:text-blue-600 transition">
              Courses
            </Link>
            <span>/</span>
            <span>{courseTitle || `Course #${courseId}`}</span>
            <span>/</span>
            <span className="text-slate-800">Assigned Live Classes</span>
          </div>
          <h1 className="text-2xl font-black text-slate-900 tracking-tight">
            Assigned Live Class Sessions
          </h1>
          <p className="text-xs text-slate-500 mt-1">
            Host live interactive Zoom/Teams classrooms, track learner attendances, and attach lecture handouts.
          </p>
        </div>
      </div>

      {/* Success Notification */}
      {success && (
        <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center gap-2">
          <span>✓</span>
          <span>{success}</span>
        </div>
      )}

      {/* Error Alert */}
      {error && (
        <div className="p-3.5 rounded-xl bg-red-50 border border-red-200 text-red-800 text-xs font-semibold flex items-center gap-2">
          <span>⚠️</span>
          <span>{error}</span>
        </div>
      )}

      {/* Live Class List Component */}
      <LiveClassList
        classes={classes}
        loading={loading}
        courseId={Number(courseId)}
        isTutorView={true}
        onRefresh={fetchLiveClasses}
        onViewAttendance={handleViewAttendance}
      />

      {/* Attendance Sheet Modal */}
      {attendanceModalClass && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-in fade-in"
          role="dialog"
          aria-modal="true"
        >
          <div className="w-full max-w-xl bg-white rounded-3xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[85vh]">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between">
              <div>
                <h3 className="text-base font-bold text-slate-900">Student Attendance Sheet</h3>
                <p className="text-xs text-slate-500 mt-0.5">{attendanceModalClass.title}</p>
              </div>
              <button
                type="button"
                onClick={() => setAttendanceModalClass(null)}
                className="text-slate-400 hover:text-slate-600 text-xl font-bold"
              >
                ✕
              </button>
            </div>

            <div className="p-6 overflow-y-auto space-y-4">
              {loadingAttendance ? (
                <div className="py-8 text-center text-xs text-slate-400 font-semibold">
                  <span className="inline-block animate-spin mr-2">⚡</span> Loading attendance records...
                </div>
              ) : attendanceList.length === 0 ? (
                <div className="py-8 text-center bg-slate-50 rounded-2xl border border-slate-100 text-xs text-slate-500">
                  No attendance records logged for this session yet.
                </div>
              ) : (
                <div className="space-y-2">
                  {attendanceList.map((att) => (
                    <div
                      key={att.id}
                      className="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between text-xs"
                    >
                      <div>
                        <p className="font-bold text-slate-900">{att.user?.name || `Student #${att.user_id}`}</p>
                        <p className="text-[10px] text-slate-400">{att.user?.email}</p>
                      </div>
                      <span className="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                        {att.status || 'Present'}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="p-4 border-t border-slate-100 flex justify-end">
              <button
                type="button"
                onClick={() => setAttendanceModalClass(null)}
                className="px-5 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Upload Material Modal */}
      {materialModalClass && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-in fade-in"
          role="dialog"
          aria-modal="true"
        >
          <div className="w-full max-w-lg bg-white rounded-3xl shadow-2xl border border-slate-200 overflow-hidden">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between">
              <div>
                <h3 className="text-base font-bold text-slate-900">Attach Session Material</h3>
                <p className="text-xs text-slate-500 mt-0.5">{materialModalClass.title}</p>
              </div>
              <button
                type="button"
                onClick={() => setMaterialModalClass(null)}
                className="text-slate-400 hover:text-slate-600 text-xl font-bold"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleUploadMaterialSubmit} className="p-6 space-y-4 text-xs">
              <div>
                <label className="block text-slate-700 font-bold mb-1">Material Title *</label>
                <input
                  type="text"
                  placeholder="e.g. Lecture Slides PDF"
                  value={materialTitle}
                  onChange={(e) => setMaterialTitle(e.target.value)}
                  required
                  className="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs focus:outline-hidden"
                />
              </div>

              <div>
                <label className="block text-slate-700 font-bold mb-1">File (PDF, Slides, DOC) *</label>
                <input
                  type="file"
                  accept=".pdf,.doc,.docx,.ppt,.pptx,.txt,.zip"
                  onChange={(e) => setMaterialFile(e.target.files?.[0] || null)}
                  required
                  className="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-blue-600 file:text-white"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setMaterialModalClass(null)}
                  className="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold text-xs"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={uploadingMaterial}
                  className="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs"
                >
                  {uploadingMaterial ? 'Uploading...' : 'Upload Material'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
