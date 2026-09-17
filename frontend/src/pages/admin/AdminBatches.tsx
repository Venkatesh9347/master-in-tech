import { useEffect, useState, useCallback, useMemo } from 'react'
import API from '../../services/api'
import type { Course } from '../../types/course'
import Pagination from '../../components/Pagination'
import { usePagedQuery } from '../../hooks/usePagedQuery'
import { extractPage } from '../../types/pagination'

interface TutorUser {
  id: number
  name: string
  email: string
  avatar?: string | null
}

interface StudentUser {
  id: number
  name: string
  email: string
  student_id?: string | null
  avatar?: string | null
  status?: string | null
  phone?: string | null
}

interface BatchStudentItem {
  id: number
  batch_id: number
  user_id: number
  status: 'active' | 'transferred' | 'discontinued' | 'completed'
  joined_at: string
  left_at?: string | null
  discontinued_at?: string | null
  discontinuation_reason?: string | null
  notes?: string | null
  user: StudentUser
}

interface BatchTransferLog {
  id: number
  user_id: number
  from_batch_id?: number | null
  to_batch_id?: number | null
  action_type: 'enrolled' | 'transferred' | 'discontinued' | 'rejoined' | 'completed'
  reason?: string | null
  created_at: string
  student?: StudentUser
  fromBatch?: { id: number; name: string; code: string }
  toBatch?: { id: number; name: string; code: string }
  performer?: { id: number; name: string; email: string }
}

interface BatchItem {
  id: number
  name: string
  code: string
  course_id: number
  tutor_id?: number | null
  start_date: string
  end_date?: string | null
  status: 'upcoming' | 'ongoing' | 'completed' | 'cancelled'
  schedule_type: string
  schedule_time?: string | null
  max_students?: number | null
  meeting_link?: string | null
  description?: string | null
  created_at: string
  course?: Course & { code?: string }
  tutor?: TutorUser
  active_batch_students_count?: number
  batch_students_count?: number
  batch_students?: BatchStudentItem[]
  transfers_from?: BatchTransferLog[]
  transfers_to?: BatchTransferLog[]
}

interface BatchStats {
  total_batches: number
  active_batches: number
  upcoming_batches: number
  completed_batches: number
  total_batch_students: number
}

// Helper functions for batch code generation and timezone-safe date formatting
function getCourseCode(course?: (Course & { code?: string }) | null): string {
  if (!course) return 'CODE'
  if (course.code && course.code.trim()) {
    return course.code.replace(/[^A-Z0-9]/g, '').toUpperCase()
  }

  const title = (course.title || '').trim()
  const cleanTitle = title.replace(/[^\w\s]/g, ' ')
  const words = cleanTitle
    .split(/\s+/)
    .filter((w) => w && !['and', '&', 'the', 'in', 'for', 'of', 'to', 'a', 'with', 'by'].includes(w.toLowerCase()))

  if (words.length >= 2) {
    return words.map((w) => w[0].toUpperCase()).join('')
  }

  if (words.length === 1) {
    const singleWord = words[0].toUpperCase()
    if (singleWord === 'PYTHON') return 'PY'
    if (singleWord === 'JAVASCRIPT') return 'JS'
    if (singleWord === 'TYPESCRIPT') return 'TS'
    if (singleWord === 'KUBERNETES') return 'K8S'
    if (singleWord === 'FLUTTER') return 'FL'
    if (singleWord === 'DJANGO') return 'DJ'
    if (singleWord === 'JAVA') return 'JAVA'
    if (singleWord === 'REACT') return 'REACT'
    if (singleWord === 'DEVOPS') return 'DEVOPS'
    if (singleWord === 'DOCKER') return 'DOCKER'
    if (singleWord.length <= 4) return singleWord
    return singleWord.slice(0, 3)
  }

  return 'COURSE'
}

function formatBatchDate(dateString: string): string {
  if (!dateString) return 'DDMMYY'

  // Direct string splitting on YYYY-MM-DD avoids browser timezone shifting
  const parts = dateString.trim().split(/[-/]/)
  if (parts.length === 3) {
    if (parts[0].length === 4) {
      // YYYY-MM-DD
      const year = parts[0].slice(-2)
      const month = parts[1].padStart(2, '0')
      const day = parts[2].padStart(2, '0')
      return `${day}${month}${year}`
    } else if (parts[2].length === 4) {
      // DD-MM-YYYY or MM-DD-YYYY
      const day = parts[0].padStart(2, '0')
      const month = parts[1].padStart(2, '0')
      const year = parts[2].slice(-2)
      return `${day}${month}${year}`
    }
  }

  try {
    const d = new Date(dateString)
    const day = String(d.getUTCDate()).padStart(2, '0')
    const month = String(d.getUTCMonth() + 1).padStart(2, '0')
    const year = String(d.getUTCFullYear()).slice(-2)
    return `${day}${month}${year}`
  } catch {
    return 'DDMMYY'
  }
}

export default function AdminBatches() {
  // Main View State: 'batches_list' | 'batch_detail' | 'transfer_audit'
  const [activeTab, setActiveTab] = useState<'batches_list' | 'batch_detail' | 'transfer_audit'>('batches_list')

  // Data States
  const [courses, setCourses] = useState<Course[]>([])
  const [tutors, setTutors] = useState<TutorUser[]>([])
  const [allStudents, setAllStudents] = useState<StudentUser[]>([])
  const [stats, setStats] = useState<BatchStats | null>(null)

  // Selected Batch for Roster/Detail View
  const [selectedBatch, setSelectedBatch] = useState<BatchItem | null>(null)
  const [loadingBatchDetail, setLoadingBatchDetail] = useState(false)

  // Filters & Search
  const [search, setSearch] = useState('')
  const [courseFilter, setCourseFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [tutorFilter, setTutorFilter] = useState('all')

  // Batches grid: server-paginated; filter/search changes reset to page 1.
  // (Declared before sixDigitDateInfo below, which reads the current page.)
  const {
    items: batches,
    meta: batchesMeta,
    loading,
    setPage: setBatchesPage,
    reload: reloadBatches,
  } = usePagedQuery<BatchItem>(
    '/admin/batches',
    {
      search: search.trim() || undefined,
      course_id: courseFilter !== 'all' ? courseFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      tutor_id: tutorFilter !== 'all' ? tutorFilter : undefined,
    },
    {
      errorMessage: 'Failed to load batches list.',
      onError: (message) => setErrorMsg(message),
    }
  )

  // Transfer audit trail: server-paginated (backend caps the window).
  const {
    items: auditLogs,
    meta: auditMeta,
    setPage: setAuditPage,
    reload: reloadAuditLogs,
  } = usePagedQuery<BatchTransferLog>('/admin/batches/history', {})

  // Modals
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [editingBatch, setEditingBatch] = useState<BatchItem | null>(null)
  const [showAddStudentModal, setShowAddStudentModal] = useState(false)
  const [showTransferModal, setShowTransferModal] = useState(false)
  const [showDiscontinueModal, setShowDiscontinueModal] = useState(false)
  const [showRejoinModal, setShowRejoinModal] = useState(false)
  const [selectedStudentMember, setSelectedStudentMember] = useState<BatchStudentItem | null>(null)

  // Form States - Batch Create/Edit
  const [formCourseId, setFormCourseId] = useState<string | number>('')
  const [formTutorId, setFormTutorId] = useState<string | number>('')
  const [formStartDate, setFormStartDate] = useState(new Date().toISOString().split('T')[0])
  const [formEndDate, setFormEndDate] = useState('')
  const [formStatus, setFormStatus] = useState<string>('upcoming')
  const [formScheduleType, setFormScheduleType] = useState('weekdays')
  const [formScheduleTime, setFormScheduleTime] = useState('09:00 AM - 11:00 AM')
  const [formMaxStudents, setFormMaxStudents] = useState<number | string>(30)
  const [formMeetingLink, setFormMeetingLink] = useState('')
  const [formDescription, setFormDescription] = useState('')
  const [formCode, setFormCode] = useState('')
  const [manualCodeOverride, setManualCodeOverride] = useState(false)

  // Form States - Action Modals
  const [addStudentId, setAddStudentId] = useState<string | number>('')
  const [addStudentSearchQuery, setAddStudentSearchQuery] = useState('')
  const [addStudentNotes, setAddStudentNotes] = useState('')
  const [transferTargetBatchId, setTransferTargetBatchId] = useState<string | number>('')
  const [transferReason, setTransferReason] = useState('')
  const [discontinueReason, setDiscontinueReason] = useState('')
  const [rejoinTargetBatchId, setRejoinTargetBatchId] = useState<string | number>('')
  const [rejoinReason, setRejoinReason] = useState('')

  // Filtered Students for Add Student Modal
  const filteredAddStudents = useMemo(() => {
    if (!addStudentSearchQuery.trim()) return allStudents
    const q = addStudentSearchQuery.toLowerCase().trim()
    return allStudents.filter(
      (s) =>
        s.name.toLowerCase().includes(q) ||
        s.email.toLowerCase().includes(q) ||
        (s.student_id && s.student_id.toLowerCase().includes(q)) ||
        (s.phone && s.phone.includes(q))
    )
  }, [allStudents, addStudentSearchQuery])

  // Six-Digit Date Info
  const sixDigitDateInfo = useMemo(() => {
    const trimmed = search.trim()
    if (!/^\d{6}$/.test(trimmed)) return null
    const day = trimmed.slice(0, 2)
    const month = trimmed.slice(2, 4)
    const year = '20' + trimmed.slice(4, 6)
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    const mIndex = parseInt(month, 10) - 1
    const mName = monthNames[mIndex] || month
    const formattedLabel = `${day}-${mName}-${year}`

    // Find all distinct courses/technologies available in batches or platform matching this date
    const matchedBatches = batches.filter((b) => {
      const bDate = formatBatchDate(b.start_date ? b.start_date.split('T')[0] : '')
      return bDate === trimmed || (b.code && b.code.includes(trimmed))
    })

    const techCourses = courses.map((c) => {
      const code = getCourseCode(c)
      const matchingBatch = matchedBatches.find((b) => b.course_id === c.id)
      return {
        course: c,
        code,
        batch: matchingBatch,
      }
    })

    return {
      raw: trimmed,
      formatted: formattedLabel,
      matchedBatches,
      techCourses,
    }
  }, [search, batches, courses])

  // Loading & Feedback (list loading comes from usePagedQuery above)
  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  // 1. Fetch Global Data
  const fetchStats = useCallback(async () => {
    try {
      const res = await API.get<BatchStats>('/admin/batches/stats')
      setStats(res.data)
    } catch {
      // Non-blocking
    }
  }, [])

  const fetchBatches = useCallback(() => {
    reloadBatches()
  }, [reloadBatches])

  const fetchAuditLogs = useCallback(() => {
    reloadAuditLogs()
  }, [reloadAuditLogs])

  useEffect(() => {
    fetchStats()
    fetchBatches()
    fetchAuditLogs()

    API.get<Course[]>('/courses')
      .then((res) => setCourses(Array.isArray(res.data) ? res.data : []))
      .catch(() => {})

    API.get<unknown>('/admin/users?role=tutor&per_page=100')
      .then((res) => setTutors(extractPage<TutorUser>(res.data).items))
      .catch(() => {})

    API.get<unknown>('/admin/users?role=student&per_page=100')
      .then((res) => setAllStudents(extractPage<StudentUser>(res.data).items))
      .catch(() => {})
  }, [fetchStats, fetchBatches, fetchAuditLogs])

  // 2. Fetch Single Batch Detail
  const fetchBatchDetail = useCallback(async (batchId: number) => {
    setLoadingBatchDetail(true)
    try {
      const res = await API.get<BatchItem>(`/admin/batches/${batchId}`)
      setSelectedBatch(res.data)
    } catch {
      setErrorMsg('Failed to load batch details.')
    } finally {
      setLoadingBatchDetail(false)
    }
  }, [])

  // 3. Automated Code Preview Generation: RIT(COURSE_CODE)BCDDMMYY
  const previewGeneratedCode = useMemo(() => {
    if (!formCourseId || !formStartDate) return 'RIT(CODE)BCDDMMYY'

    const selectedCourse = courses.find((c) => c.id === Number(formCourseId))
    const code = getCourseCode(selectedCourse)
    const dateStr = formatBatchDate(formStartDate)

    return `RIT(${code})BC${dateStr}`
  }, [formCourseId, formStartDate, courses])

  // Reset Create Form
  const openCreateBatchModal = () => {
    setEditingBatch(null)
    setFormCourseId(courses.length > 0 ? courses[0].id : '')
    setFormTutorId(tutors.length > 0 ? tutors[0].id : '')
    const today = new Date().toISOString().split('T')[0]
    setFormStartDate(today)
    setFormEndDate('')
    setFormStatus('upcoming')
    setFormScheduleType('weekdays')
    setFormScheduleTime('09:00 AM - 11:00 AM')
    setFormMaxStudents(30)
    setFormMeetingLink('')
    setFormDescription('')
    setFormCode('')
    setManualCodeOverride(false)
    setShowCreateModal(true)
  }

  // Open Edit Form
  const openEditBatchModal = (batch: BatchItem) => {
    setEditingBatch(batch)
    setFormCourseId(batch.course_id)
    setFormTutorId(batch.tutor_id || '')
    setFormStartDate(batch.start_date ? batch.start_date.split('T')[0] : '')
    setFormEndDate(batch.end_date ? batch.end_date.split('T')[0] : '')
    setFormStatus(batch.status)
    setFormScheduleType(batch.schedule_type || 'weekdays')
    setFormScheduleTime(batch.schedule_time || '')
    setFormMaxStudents(batch.max_students || '')
    setFormMeetingLink(batch.meeting_link || '')
    setFormDescription(batch.description || '')
    setFormCode(batch.code)
    setManualCodeOverride(true)
    setShowCreateModal(true)
  }

  // Submit Batch Create/Edit
  const handleSaveBatch = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const isCustom = manualCodeOverride && Boolean(formCode.trim())
    const batchCodeToSend = isCustom ? formCode.trim().toUpperCase() : previewGeneratedCode

    const payload = {
      course_id: Number(formCourseId),
      tutor_id: formTutorId ? Number(formTutorId) : null,
      start_date: formStartDate,
      end_date: formEndDate || null,
      status: formStatus,
      schedule_type: formScheduleType,
      schedule_time: formScheduleTime,
      max_students: formMaxStudents ? Number(formMaxStudents) : null,
      meeting_link: formMeetingLink || null,
      description: formDescription || null,
      code: batchCodeToSend,
      is_custom_code: isCustom,
    }

    try {
      if (editingBatch) {
        await API.put(`/admin/batches/${editingBatch.id}`, payload)
        setSuccessMsg(`✓ Batch ${payload.code} updated successfully!`)
      } else {
        const res = await API.post<{ message: string; batch: BatchItem }>('/admin/batches', payload)
        setSuccessMsg(`✓ Batch ${res.data.batch.code} created successfully!`)
      }
      setShowCreateModal(false)
      fetchBatches()
      fetchStats()
      if (selectedBatch && editingBatch && selectedBatch.id === editingBatch.id) {
        fetchBatchDetail(selectedBatch.id)
      }
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to save batch.')
    } finally {
      setSaving(false)
    }
  }

  // Delete Batch
  const handleDeleteBatch = async (batch: BatchItem) => {
    if (!window.confirm(`Are you sure you want to delete batch "${batch.name}" (${batch.code})?`)) return
    try {
      await API.delete(`/admin/batches/${batch.id}`)
      setSuccessMsg(`✓ Batch ${batch.code} deleted successfully.`)
      fetchBatches()
      fetchStats()
      if (selectedBatch && selectedBatch.id === batch.id) {
        setSelectedBatch(null)
        setActiveTab('batches_list')
      }
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to delete batch.')
    }
  }

  // 4. Student Management in Selected Batch
  const handleAddStudentToBatch = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedBatch || !addStudentId) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(`/admin/batches/${selectedBatch.id}/students`, {
        user_id: Number(addStudentId),
        notes: addStudentNotes.trim() || undefined,
      })
      const studentName = allStudents.find((s) => s.id === Number(addStudentId))?.name || 'Student'
      setSuccessMsg(`✓ Added ${studentName} to batch ${selectedBatch.code} successfully!`)
      setShowAddStudentModal(false)
      setAddStudentId('')
      setAddStudentNotes('')
      fetchBatchDetail(selectedBatch.id)
      fetchStats()
      fetchBatches()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to add student to batch.')
    } finally {
      setSaving(false)
    }
  }

  // Transfer Student
  const handleTransferStudentSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedBatch || !selectedStudentMember || !transferTargetBatchId) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(
        `/admin/batches/${selectedBatch.id}/students/${selectedStudentMember.user_id}/transfer`,
        {
          to_batch_id: Number(transferTargetBatchId),
          reason: transferReason.trim() || 'Transferred by Admin',
        }
      )
      const targetCode = batches.find((b) => b.id === Number(transferTargetBatchId))?.code || 'Destination Batch'
      setSuccessMsg(`✓ Student ${selectedStudentMember.user.name} transferred to ${targetCode} successfully!`)
      setShowTransferModal(false)
      setSelectedStudentMember(null)
      setTransferTargetBatchId('')
      setTransferReason('')
      fetchBatchDetail(selectedBatch.id)
      fetchBatches()
      fetchAuditLogs()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to transfer student.')
    } finally {
      setSaving(false)
    }
  }

  // Discontinue Student
  const handleDiscontinueStudentSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedBatch || !selectedStudentMember || !discontinueReason.trim()) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(
        `/admin/batches/${selectedBatch.id}/students/${selectedStudentMember.user_id}/discontinue`,
        {
          reason: discontinueReason.trim(),
        }
      )
      setSuccessMsg(`✓ Student ${selectedStudentMember.user.name} marked as discontinued from ${selectedBatch.code}.`)
      setShowDiscontinueModal(false)
      setSelectedStudentMember(null)
      setDiscontinueReason('')
      fetchBatchDetail(selectedBatch.id)
      fetchBatches()
      fetchAuditLogs()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to discontinue student.')
    } finally {
      setSaving(false)
    }
  }

  // Rejoin Student
  const handleRejoinStudentSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedBatch || !selectedStudentMember) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(
        `/admin/batches/${selectedBatch.id}/students/${selectedStudentMember.user_id}/rejoin`,
        {
          target_batch_id: rejoinTargetBatchId ? Number(rejoinTargetBatchId) : selectedBatch.id,
          reason: rejoinReason.trim() || 'Rejoined cohort',
        }
      )
      setSuccessMsg(`✓ Student ${selectedStudentMember.user.name} successfully rejoined!`)
      setShowRejoinModal(false)
      setSelectedStudentMember(null)
      setRejoinTargetBatchId('')
      setRejoinReason('')
      fetchBatchDetail(selectedBatch.id)
      fetchBatches()
      fetchAuditLogs()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to rejoin student.')
    } finally {
      setSaving(false)
    }
  }

  // Remove Student from Batch
  const handleRemoveStudentFromBatch = async (studentId: number, studentName: string) => {
    if (!selectedBatch) return
    if (!window.confirm(`Remove ${studentName} completely from batch ${selectedBatch.code}?`)) return
    try {
      await API.delete(`/admin/batches/${selectedBatch.id}/students/${studentId}`)
      setSuccessMsg(`✓ Student ${studentName} removed from batch ${selectedBatch.code}.`)
      fetchBatchDetail(selectedBatch.id)
      fetchBatches()
      fetchStats()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to remove student.')
    }
  }

  return (
    <div className="space-y-8">
      {/* 1. Header Banner */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center gap-2.5">
            <span className="p-2 rounded-xl bg-purple-950/80 border border-purple-800 text-purple-400 text-xl shadow-inner">
              📦
            </span>
            Batch Management & Cohort Operations
          </h1>
          <p className="text-slate-400 text-sm mt-1">
            Organize courses into cohorts with automated <code className="text-purple-300 font-mono">RIT(CODE)BCDDMMYY</code> identifiers, 6-digit date search, student rosters, transfers, and complete history.
          </p>
        </div>

        <div className="flex items-center gap-3 self-start md:self-auto flex-wrap">
          {/* Create Batch Button */}
          <button
            type="button"
            onClick={openCreateBatchModal}
            className="px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2"
          >
            <span>➕</span>
            <span>Create New Batch</span>
          </button>
        </div>
      </div>

      {/* 2. Platform KPI Metrics */}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-purple-500/50 transition">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Batches</span>
            <span className="text-lg">📦</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-white">{stats?.total_batches ?? batches.length}</span>
            <span className="text-xs font-semibold text-purple-400">cohorts</span>
          </div>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-emerald-500/50 transition">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Ongoing Batches</span>
            <span className="text-lg">⚡</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-emerald-400">{stats?.active_batches ?? '...'}</span>
            <span className="text-xs font-semibold text-slate-400">in session</span>
          </div>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-blue-500/50 transition">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Upcoming Batches</span>
            <span className="text-lg">📅</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-blue-400">{stats?.upcoming_batches ?? '...'}</span>
            <span className="text-xs font-semibold text-slate-400">scheduled</span>
          </div>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-indigo-500/50 transition">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Completed Batches</span>
            <span className="text-lg">🏆</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-indigo-400">{stats?.completed_batches ?? '...'}</span>
            <span className="text-xs font-semibold text-slate-400">graduated</span>
          </div>
        </div>

        <div className="bg-slate-950/80 backdrop-blur border border-slate-800/80 rounded-2xl p-5 shadow-lg relative overflow-hidden group hover:border-cyan-500/50 transition col-span-2 lg:col-span-1">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Active Students</span>
            <span className="text-lg">👥</span>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-black text-cyan-400">{stats?.total_batch_students ?? '...'}</span>
            <span className="text-xs font-semibold text-slate-400">in cohorts</span>
          </div>
        </div>
      </div>

      {/* 3. Alerts */}
      {successMsg && (
        <div className="bg-emerald-950/80 border border-emerald-800 text-emerald-200 px-5 py-3.5 rounded-2xl text-sm font-semibold flex items-center justify-between shadow-lg animate-in fade-in duration-200">
          <div className="flex items-center gap-3">
            <span className="text-lg">✓</span>
            <span>{successMsg}</span>
          </div>
          <button
            type="button"
            onClick={() => setSuccessMsg('')}
            className="text-emerald-400 hover:text-white text-xs font-bold px-2 py-1 rounded-lg hover:bg-emerald-900 transition"
          >
            ✕
          </button>
        </div>
      )}

      {errorMsg && (
        <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-5 py-3.5 rounded-2xl text-sm font-semibold flex items-center justify-between shadow-lg animate-in fade-in duration-200">
          <div className="flex items-center gap-3">
            <span className="text-lg">⚠️</span>
            <span>{errorMsg}</span>
          </div>
          <button
            type="button"
            onClick={() => setErrorMsg('')}
            className="text-rose-400 hover:text-white text-xs font-bold px-2 py-1 rounded-lg hover:bg-rose-900 transition"
          >
            ✕
          </button>
        </div>
      )}

      {/* 4. Main Navigation Tabs for Workspace */}
      <div className="flex items-center gap-3 border-b border-slate-800 pb-2">
        <button
          type="button"
          onClick={() => setActiveTab('batches_list')}
          className={`px-4 py-2.5 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
            activeTab === 'batches_list'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>📦</span>
          <span>All Batches Roster ({batches.length})</span>
        </button>

        {selectedBatch && (
          <button
            type="button"
            onClick={() => setActiveTab('batch_detail')}
            className={`px-4 py-2.5 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
              activeTab === 'batch_detail'
                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                : 'text-slate-400 hover:text-white hover:bg-slate-900'
            }`}
          >
            <span>👥</span>
            <span>Batch: {selectedBatch.code}</span>
          </button>
        )}

        <button
          type="button"
          onClick={() => {
            setActiveTab('transfer_audit')
            fetchAuditLogs()
          }}
          className={`px-4 py-2.5 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
            activeTab === 'transfer_audit'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>📋</span>
          <span>Transfer & Movement Audit Logs</span>
        </button>
      </div>

      {/* 5. VIEW A: BATCHES ROSTER & GRID */}
      {activeTab === 'batches_list' && (
        <div className="space-y-6">
          {/* Search and Filters Bar */}
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
              {/* Intelligent Search (including 6-digit date DDMMYY) */}
              <div className="lg:col-span-5 relative">
                <span className="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500 text-sm">
                  🔍
                </span>
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search code, 6-digit date (e.g. 230826), course, tutor..."
                  className="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                />
                {search && (
                  <button
                    type="button"
                    onClick={() => setSearch('')}
                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-white text-xs"
                  >
                    ✕
                  </button>
                )}
              </div>

              {/* Course / Technology Filter */}
              <div className="lg:col-span-3">
                <select
                  value={courseFilter}
                  onChange={(e) => setCourseFilter(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Courses & Technologies</option>
                  {courses.map((c) => (
                    <option key={c.id} value={String(c.id)}>
                      {c.title} {c.category ? `(${c.category})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              {/* Status Filter */}
              <div className="lg:col-span-2">
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Statuses</option>
                  <option value="ongoing">Ongoing (Active)</option>
                  <option value="upcoming">Upcoming</option>
                  <option value="completed">Completed</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>

              {/* Tutor Filter */}
              <div className="lg:col-span-2">
                <select
                  value={tutorFilter}
                  onChange={(e) => setTutorFilter(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Faculty Tutors</option>
                  {tutors.map((t) => (
                    <option key={t.id} value={String(t.id)}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* 6-Digit Date Search Helper & Technology Disambiguation Chips */}
            {sixDigitDateInfo ? (
              <div className="bg-purple-950/70 border border-purple-800/90 rounded-2xl p-4 space-y-3">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span className="text-sm">📅</span>
                    <span className="text-xs font-black text-white">
                      6-Digit Batch Date Code: <code className="text-purple-300 font-mono bg-purple-900/60 px-1.5 py-0.5 rounded">{sixDigitDateInfo.raw}</code> ({sixDigitDateInfo.formatted})
                    </span>
                  </div>
                  <span className="text-[11px] font-bold text-purple-300">
                    {sixDigitDateInfo.matchedBatches.length} Cohort(s) Found on this Date
                  </span>
                </div>

                <div className="border-t border-purple-800/60 pt-2.5">
                  <p className="text-[11px] font-bold text-slate-300 mb-2 flex items-center gap-1.5">
                    <span>⚡</span>
                    <span>Select relevant Course / Programming Technology to view students:</span>
                  </p>
                  <div className="flex items-center gap-2 flex-wrap">
                    <button
                      type="button"
                      onClick={() => setCourseFilter('all')}
                      className={`px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
                        courseFilter === 'all'
                          ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                          : 'bg-slate-900 text-slate-300 hover:bg-slate-800 border border-slate-800 hover:text-white'
                      }`}
                    >
                      <span>🌐</span>
                      <span>All Technologies</span>
                    </button>

                    {courses.map((c) => {
                      const code = getCourseCode(c)
                      const matchingBatch = sixDigitDateInfo.matchedBatches.find((b) => b.course_id === c.id)
                      const isSelected = courseFilter === String(c.id)

                      return (
                        <button
                          key={c.id}
                          type="button"
                          onClick={() => {
                            if (matchingBatch) {
                              setSelectedBatch(matchingBatch)
                              setActiveTab('batch_detail')
                              fetchBatchDetail(matchingBatch.id)
                            } else {
                              setCourseFilter(String(c.id))
                            }
                          }}
                          className={`px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
                            matchingBatch
                              ? isSelected
                                ? 'bg-emerald-600 text-white shadow-md'
                                : 'bg-emerald-950/80 text-emerald-300 border border-emerald-800 hover:bg-emerald-900'
                              : isSelected
                              ? 'bg-purple-600 text-white shadow-md'
                              : 'bg-slate-900 text-slate-400 hover:bg-slate-800 border border-slate-800 hover:text-white'
                          }`}
                        >
                          <span className="font-mono text-[10px] uppercase px-1 py-0.5 rounded bg-black/40 text-purple-200">
                            {code}
                          </span>
                          <span>{c.title}</span>
                          {matchingBatch && (
                            <span className="text-[10px] font-black text-emerald-400 bg-emerald-900/60 px-1.5 py-0.5 rounded-full">
                              ✓ {matchingBatch.active_batch_students_count ?? 0} Students
                            </span>
                          )}
                        </button>
                      )
                    })}
                  </div>
                </div>
              </div>
            ) : (
              <div className="text-[11px] text-slate-400 flex items-center justify-between border-t border-slate-800/80 pt-3">
                <span className="flex items-center gap-1.5">
                  <span className="text-purple-400 font-bold">💡 Tip:</span> Type a 6-digit date like <code className="text-purple-300 font-mono bg-purple-950/80 px-1 py-0.5 rounded">230826</code> in search to find batches starting on 23-Aug-2026 and select technology.
                </span>
                <span>Showing {batchesMeta ? batchesMeta.total : batches.length} Batches</span>
              </div>
            )}
          </div>

          {/* Batches Grid */}
          {loading ? (
            <div className="py-24 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
              <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
              <span>Loading batches...</span>
            </div>
          ) : batches.length === 0 ? (
            <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-3">
              <span className="text-4xl">📭</span>
              <h3 className="font-extrabold text-base text-slate-300">No Batches Found</h3>
              <p className="text-xs text-slate-400 max-w-md mx-auto">
                No cohort matches your current search criteria. Click "+ Create New Batch" above to start a new batch.
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {batches.map((batch) => {
                const activeCount = batch.active_batch_students_count ?? 0
                const capacity = batch.max_students || 30
                const capacityPct = Math.min(100, Math.round((activeCount / capacity) * 100))

                return (
                  <div
                    key={batch.id}
                    className="bg-slate-950/80 backdrop-blur border border-slate-800 hover:border-purple-500/50 rounded-3xl p-6 shadow-xl transition flex flex-col justify-between group relative overflow-hidden"
                  >
                    <div>
                      {/* Top Badges */}
                      <div className="flex items-center justify-between gap-2 mb-3">
                        <span className="px-2.5 py-1 rounded-xl text-xs font-mono font-black bg-purple-950 text-purple-300 border border-purple-800 shadow-inner">
                          {batch.code}
                        </span>

                        <span
                          className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                            batch.status === 'ongoing'
                              ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                              : batch.status === 'upcoming'
                              ? 'bg-blue-950 text-blue-400 border border-blue-800'
                              : batch.status === 'completed'
                              ? 'bg-purple-950 text-purple-400 border border-purple-800'
                              : 'bg-rose-950 text-rose-400 border border-rose-800'
                          }`}
                        >
                          {batch.status}
                        </span>
                      </div>

                      {/* Name & Course */}
                      <h4 className="font-black text-base text-white group-hover:text-purple-300 transition leading-tight">
                        {batch.name}
                      </h4>
                      <p className="text-xs font-bold text-slate-400 mt-1 flex items-center gap-1.5">
                        <span>📚</span>
                        <span>{batch.course?.title || `Course #${batch.course_id}`}</span>
                      </p>

                      {/* Schedule & Faculty Metadata */}
                      <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-3 mt-4 space-y-2 text-xs">
                        <div className="flex items-center justify-between text-slate-400">
                          <span className="flex items-center gap-1.5">
                            <span>📅</span>
                            <span>Start Date:</span>
                          </span>
                          <strong className="text-white font-mono">
                            {new Date(batch.start_date).toLocaleDateString()}
                          </strong>
                        </div>

                        {batch.schedule_time && (
                          <div className="flex items-center justify-between text-slate-400">
                            <span className="flex items-center gap-1.5">
                              <span>⏰</span>
                              <span>Timing:</span>
                            </span>
                            <span className="text-slate-200 font-semibold">{batch.schedule_time}</span>
                          </div>
                        )}

                        <div className="flex items-center justify-between text-slate-400">
                          <span className="flex items-center gap-1.5">
                            <span>👨‍🏫</span>
                            <span>Instructor:</span>
                          </span>
                          <span className="text-purple-300 font-bold">
                            {batch.tutor?.name || 'Unassigned'}
                          </span>
                        </div>
                      </div>

                      {/* Capacity Meter */}
                      <div className="mt-4">
                        <div className="flex items-center justify-between text-[11px] font-bold text-slate-400 mb-1">
                          <span>Batch Enrollment</span>
                          <span className="text-white">
                            {activeCount} / {capacity} Students ({capacityPct}%)
                          </span>
                        </div>
                        <div className="w-full h-2 rounded-full bg-slate-900 border border-slate-800 overflow-hidden">
                          <div
                            className={`h-full rounded-full transition-all duration-500 ${
                              capacityPct >= 100
                                ? 'bg-rose-500'
                                : capacityPct > 60
                                ? 'bg-purple-500'
                                : 'bg-emerald-500'
                            }`}
                            style={{ width: `${capacityPct}%` }}
                          />
                        </div>
                      </div>
                    </div>

                    {/* Card Actions */}
                    <div className="flex items-center gap-2 pt-5 mt-5 border-t border-slate-800/80">
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedBatch(batch)
                          setActiveTab('batch_detail')
                          fetchBatchDetail(batch.id)
                        }}
                        className="flex-grow py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600/90 hover:bg-purple-500 shadow-md transition flex items-center justify-center gap-1.5"
                      >
                        <span>👥</span>
                        <span>Manage Students</span>
                      </button>

                      <button
                        type="button"
                        onClick={() => openEditBatchModal(batch)}
                        title="Edit Batch Details"
                        className="p-2 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:text-white transition"
                      >
                        ✏️
                      </button>

                      <button
                        type="button"
                        onClick={() => handleDeleteBatch(batch)}
                        title="Delete Batch"
                        className="p-2 rounded-xl text-xs font-bold text-rose-400 bg-rose-950/40 hover:bg-rose-900 border border-rose-900/50 hover:text-white transition"
                      >
                        🗑️
                      </button>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
          {batchesMeta && (
            <Pagination meta={batchesMeta} onPageChange={setBatchesPage} label="Batches list pagination" />
          )}
        </div>
      )}

      {/* 6. VIEW B: SELECTED BATCH DETAIL & STUDENT ROSTER */}
      {activeTab === 'batch_detail' && selectedBatch && (
        <div className="space-y-6">
          {/* Header Card */}
          <div className="bg-slate-950/80 backdrop-blur border border-purple-900/50 rounded-3xl p-6 shadow-xl relative overflow-hidden">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="flex items-start gap-4">
                <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-purple-700 to-indigo-600 flex items-center justify-center text-2xl text-white font-black shadow-lg shadow-purple-600/30 shrink-0">
                  📦
                </div>
                <div>
                  <div className="flex items-center gap-2.5 flex-wrap">
                    <span className="px-3 py-1 rounded-xl text-xs font-mono font-black bg-purple-950 text-purple-300 border border-purple-800 shadow-inner">
                      {selectedBatch.code}
                    </span>
                    <h3 className="font-black text-xl text-white">{selectedBatch.name}</h3>
                    <span
                      className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                        selectedBatch.status === 'ongoing'
                          ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                          : selectedBatch.status === 'upcoming'
                          ? 'bg-blue-950 text-blue-400 border border-blue-800'
                          : 'bg-purple-950 text-purple-400 border border-purple-800'
                      }`}
                    >
                      {selectedBatch.status}
                    </span>
                  </div>

                  <p className="text-xs text-slate-400 mt-1.5 flex items-center gap-4 flex-wrap">
                    <span>📚 {selectedBatch.course?.title}</span>
                    <span>•</span>
                    <span>👨‍🏫 Faculty: {selectedBatch.tutor?.name || 'Unassigned'}</span>
                    <span>•</span>
                    <span>📅 Start: {new Date(selectedBatch.start_date).toLocaleDateString()}</span>
                    {selectedBatch.schedule_time && (
                      <>
                        <span>•</span>
                        <span>⏰ {selectedBatch.schedule_time}</span>
                      </>
                    )}
                  </p>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex items-center gap-2.5 self-start md:self-auto flex-wrap">
                <button
                  type="button"
                  onClick={() => setShowAddStudentModal(true)}
                  className="px-4 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-1.5"
                >
                  <span>➕</span>
                  <span>Add Student to Cohort</span>
                </button>

                <button
                  type="button"
                  onClick={() => openEditBatchModal(selectedBatch)}
                  className="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:text-white transition flex items-center gap-1.5"
                >
                  <span>✏️</span>
                  <span>Edit Batch</span>
                </button>

                <button
                  type="button"
                  onClick={() => setActiveTab('batches_list')}
                  className="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-400 bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:text-white transition"
                >
                  ← Back to List
                </button>
              </div>
            </div>
          </div>

          {/* Student Roster Table */}
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <div>
                <h4 className="font-extrabold text-base text-white flex items-center gap-2">
                  <span>👥</span> Cohort Student Membership Roster
                </h4>
                <p className="text-xs text-slate-400 mt-0.5">
                  Complete membership log for {selectedBatch.code} (active, transferred, and discontinued)
                </p>
              </div>
              <span className="text-xs font-bold px-3 py-1 rounded-full bg-purple-950 text-purple-300 border border-purple-800">
                {selectedBatch.batch_students?.length ?? 0} Total Records
              </span>
            </div>

            {loadingBatchDetail ? (
              <div className="py-16 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
                <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
                <span>Loading student roster...</span>
              </div>
            ) : !selectedBatch.batch_students || selectedBatch.batch_students.length === 0 ? (
              <div className="py-16 text-center text-slate-500 text-xs flex flex-col items-center gap-2">
                <span className="text-3xl">👥</span>
                <span className="font-bold text-slate-400">No Students in this Batch Yet</span>
                <p className="text-slate-500 max-w-sm">
                  Click "+ Add Student to Cohort" above to enroll students into {selectedBatch.code}.
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto rounded-2xl border border-slate-800">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                    <tr>
                      <th className="py-3.5 px-4">Student</th>
                      <th className="py-3.5 px-4">Joined Date</th>
                      <th className="py-3.5 px-4">Status</th>
                      <th className="py-3.5 px-4">Notes / Transfer History</th>
                      <th className="py-3.5 px-4 text-right">Student Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/80 bg-slate-950/40">
                    {selectedBatch.batch_students.map((member) => {
                      const isActive = member.status === 'active'
                      const isDiscontinued = member.status === 'discontinued'
                      const isTransferred = member.status === 'transferred'

                      return (
                        <tr key={member.id} className="hover:bg-slate-900/50 transition">
                          {/* Student Info */}
                          <td className="py-3.5 px-4">
                            <div className="flex items-center gap-3">
                              <div className="w-8 h-8 rounded-xl bg-purple-950 border border-purple-800 flex items-center justify-center font-bold text-xs text-purple-300 uppercase shrink-0">
                                {member.user?.name?.charAt(0) || 'S'}
                              </div>
                              <div>
                                <div className="flex items-center gap-2">
                                  <p className="font-bold text-white leading-tight">{member.user?.name}</p>
                                  {member.user?.student_id && (
                                    <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-purple-300">
                                      {member.user.student_id}
                                    </span>
                                  )}
                                </div>
                                <p className="text-[11px] text-slate-400 mt-0.5">{member.user?.email}</p>
                              </div>
                            </div>
                          </td>

                          {/* Joined Date */}
                          <td className="py-3.5 px-4 text-slate-300 font-mono">
                            {member.joined_at ? new Date(member.joined_at).toLocaleDateString() : '—'}
                          </td>

                          {/* Status */}
                          <td className="py-3.5 px-4">
                            <span
                              className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                                isActive
                                  ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                                  : isDiscontinued
                                  ? 'bg-amber-950 text-amber-400 border border-amber-800'
                                  : isTransferred
                                  ? 'bg-blue-950 text-blue-400 border border-blue-800'
                                  : 'bg-purple-950 text-purple-400 border border-purple-800'
                              }`}
                            >
                              {member.status}
                            </span>
                          </td>

                          {/* Notes / Reason */}
                          <td className="py-3.5 px-4 text-slate-400 text-[11px] max-w-xs truncate">
                            {member.discontinuation_reason || member.notes || '—'}
                          </td>

                          {/* Actions */}
                          <td className="py-3.5 px-4 text-right">
                            <div className="flex items-center justify-end gap-1.5">
                              {isActive && (
                                <>
                                  {/* Transfer Button */}
                                  <button
                                    type="button"
                                    onClick={() => {
                                      setSelectedStudentMember(member)
                                      setTransferTargetBatchId('')
                                      setTransferReason('')
                                      setShowTransferModal(true)
                                    }}
                                    className="px-2.5 py-1 rounded-lg text-xs font-bold text-blue-400 bg-blue-950/60 hover:bg-blue-900 border border-blue-800 transition flex items-center gap-1"
                                    title="Transfer student to another batch"
                                  >
                                    <span>🔁</span>
                                    <span>Transfer</span>
                                  </button>

                                  {/* Discontinue Button */}
                                  <button
                                    type="button"
                                    onClick={() => {
                                      setSelectedStudentMember(member)
                                      setDiscontinueReason('')
                                      setShowDiscontinueModal(true)
                                    }}
                                    className="px-2.5 py-1 rounded-lg text-xs font-bold text-amber-400 bg-amber-950/60 hover:bg-amber-900 border border-amber-800 transition flex items-center gap-1"
                                    title="Mark student as discontinued"
                                  >
                                    <span>⏸️</span>
                                    <span>Discontinue</span>
                                  </button>
                                </>
                              )}

                              {isDiscontinued && (
                                <button
                                  type="button"
                                  onClick={() => {
                                    setSelectedStudentMember(member)
                                    setRejoinTargetBatchId(selectedBatch.id)
                                    setRejoinReason('')
                                    setShowRejoinModal(true)
                                  }}
                                  className="px-2.5 py-1 rounded-lg text-xs font-bold text-emerald-400 bg-emerald-950/60 hover:bg-emerald-900 border border-emerald-800 transition flex items-center gap-1"
                                  title="Rejoin student into batch cohort"
                                >
                                  <span>▶️</span>
                                  <span>Rejoin Cohort</span>
                                </button>
                              )}

                              {/* Remove Button */}
                              <button
                                type="button"
                                onClick={() => handleRemoveStudentFromBatch(member.user_id, member.user.name)}
                                className="p-1 rounded-lg text-rose-400 hover:text-white bg-rose-950/40 hover:bg-rose-900 border border-rose-900 transition text-xs"
                                title="Remove from batch"
                              >
                                🗑️
                              </button>
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* 7. VIEW C: TRANSFER & MOVEMENT AUDIT TRAIL */}
      {activeTab === 'transfer_audit' && (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-4">
          <div className="flex items-center justify-between pb-3 border-b border-slate-800">
            <div>
              <h4 className="font-extrabold text-base text-white flex items-center gap-2">
                <span>📋</span> Complete Batch Movement & Transfer Audit Logs
              </h4>
              <p className="text-xs text-slate-400 mt-0.5">
                Chronological timeline of all student batch admissions, transfers, discontinuations, and rejoins.
              </p>
            </div>
            <button
              type="button"
              onClick={fetchAuditLogs}
              className="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-300 bg-slate-900 hover:bg-slate-800 border border-slate-800 transition"
            >
              🔄 Refresh Logs
            </button>
          </div>

          <div className="overflow-x-auto rounded-2xl border border-slate-800">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                <tr>
                  <th className="py-3.5 px-4">Date & Time</th>
                  <th className="py-3.5 px-4">Student</th>
                  <th className="py-3.5 px-4">Action</th>
                  <th className="py-3.5 px-4">Movement Trail</th>
                  <th className="py-3.5 px-4">Reason / Notes</th>
                  <th className="py-3.5 px-4">Performed By</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/80 bg-slate-950/40">
                {auditLogs.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500 font-semibold">
                      No batch transfers or lifecycle events recorded yet.
                    </td>
                  </tr>
                ) : (
                  auditLogs.map((log) => (
                    <tr key={log.id} className="hover:bg-slate-900/50 transition">
                      <td className="py-3.5 px-4 text-slate-400 font-mono">
                        {new Date(log.created_at).toLocaleString()}
                      </td>

                      <td className="py-3.5 px-4 font-bold text-white">
                        {log.student?.name || `User #${log.user_id}`}
                      </td>

                      <td className="py-3.5 px-4">
                        <span
                          className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                            log.action_type === 'transferred'
                              ? 'bg-blue-950 text-blue-400 border border-blue-800'
                              : log.action_type === 'discontinued'
                              ? 'bg-amber-950 text-amber-400 border border-amber-800'
                              : log.action_type === 'rejoined'
                              ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                              : 'bg-purple-950 text-purple-400 border border-purple-800'
                          }`}
                        >
                          {log.action_type}
                        </span>
                      </td>

                      <td className="py-3.5 px-4">
                        <div className="flex items-center gap-1.5 font-mono text-[11px]">
                          {log.fromBatch ? (
                            <span className="text-slate-400">{log.fromBatch.code}</span>
                          ) : (
                            <span className="text-slate-500">None</span>
                          )}
                          <span className="text-purple-400">→</span>
                          {log.toBatch ? (
                            <span className="text-purple-300 font-bold">{log.toBatch.code}</span>
                          ) : (
                            <span className="text-amber-400">Discontinued</span>
                          )}
                        </div>
                      </td>

                      <td className="py-3.5 px-4 text-slate-300 max-w-xs truncate">
                        {log.reason || '—'}
                      </td>

                      <td className="py-3.5 px-4 text-slate-400 text-[11px]">
                        {log.performer?.name || 'Admin'}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
          {auditMeta && (
            <Pagination meta={auditMeta} onPageChange={setAuditPage} label="Batch transfer history pagination" />
          )}
        </div>
      )}

      {/* 8. MODAL: CREATE / EDIT BATCH */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-2xl w-full shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>📦</span> {editingBatch ? `Edit Batch (${editingBatch.code})` : 'Create New Batch Cohort'}
              </h3>
              <button
                type="button"
                onClick={() => setShowCreateModal(false)}
                className="text-slate-400 hover:text-white text-sm p-1 rounded-lg hover:bg-slate-800 transition"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSaveBatch} className="space-y-4">
              {/* Course & Automatic Code Preview */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Select Course / Technology *
                  </label>
                  <select
                    value={formCourseId}
                    onChange={(e) => setFormCourseId(e.target.value)}
                    required
                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  >
                    <option value="">-- Choose Course --</option>
                    {courses.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.title} {c.category ? `(${c.category})` : ''}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Start Date *
                  </label>
                  <input
                    type="date"
                    value={formStartDate}
                    onChange={(e) => setFormStartDate(e.target.value)}
                    required
                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition font-mono"
                  />
                </div>
              </div>

              {/* Automatic Code Preview Box */}
              <div className="bg-purple-950/60 border border-purple-800/80 rounded-2xl p-3.5 flex items-center justify-between gap-4">
                <div>
                  <span className="text-[10px] font-extrabold uppercase tracking-wider text-purple-300 block">
                    Automatic Standard Batch Code (RIT(CODE)BCDDMMYY)
                  </span>
                  <p className="font-mono text-base font-black text-white mt-0.5">
                    {manualCodeOverride && formCode ? formCode : previewGeneratedCode}
                  </p>
                </div>

                <button
                  type="button"
                  onClick={() => {
                    if (!manualCodeOverride) setFormCode(previewGeneratedCode)
                    setManualCodeOverride(!manualCodeOverride)
                  }}
                  className="px-3 py-1.5 rounded-xl text-xs font-bold text-purple-300 bg-purple-900/80 hover:bg-purple-800 transition"
                >
                  {manualCodeOverride ? 'Use Auto Code' : 'Custom Code'}
                </button>
              </div>

              {manualCodeOverride && (
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Custom Batch Identifier
                  </label>
                  <input
                    type="text"
                    value={formCode}
                    onChange={(e) => setFormCode(e.target.value.toUpperCase())}
                    placeholder="e.g. RIT(FSD)BC230826"
                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-mono font-bold text-white focus:outline-none focus:border-purple-500 transition"
                  />
                </div>
              )}

              {/* Assigned Faculty Instructor */}
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Assigned Faculty Instructor
                </label>
                <select
                  value={formTutorId}
                  onChange={(e) => setFormTutorId(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="">-- Assign Instructor (Optional) --</option>
                  {tutors.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </div>

              {/* Schedule, Timing, Capacity & Status */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Schedule Type
                  </label>
                  <select
                    value={formScheduleType}
                    onChange={(e) => setFormScheduleType(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  >
                    <option value="weekdays">Weekdays (Mon-Fri)</option>
                    <option value="weekends">Weekends (Sat-Sun)</option>
                    <option value="daily">Daily</option>
                    <option value="custom">Custom</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Daily Timings
                  </label>
                  <input
                    type="text"
                    value={formScheduleTime}
                    onChange={(e) => setFormScheduleTime(e.target.value)}
                    placeholder="e.g. 09:00 AM - 11:00 AM"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Capacity (Max Students)
                  </label>
                  <input
                    type="number"
                    value={formMaxStudents}
                    onChange={(e) => setFormMaxStudents(e.target.value)}
                    placeholder="30"
                    min={1}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  />
                </div>
              </div>

              {/* Status & Meeting Link */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Cohort Status
                  </label>
                  <select
                    value={formStatus}
                    onChange={(e) => setFormStatus(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  >
                    <option value="upcoming">Upcoming (Enrollment Open)</option>
                    <option value="ongoing">Ongoing (In Session)</option>
                    <option value="completed">Completed (Graduated)</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Live Classroom / Meeting URL
                  </label>
                  <input
                    type="url"
                    value={formMeetingLink}
                    onChange={(e) => setFormMeetingLink(e.target.value)}
                    placeholder="https://zoom.us/j/... or Google Meet"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                  />
                </div>
              </div>

              {/* Description */}
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Description / Cohort Notes
                </label>
                <textarea
                  rows={2}
                  value={formDescription}
                  onChange={(e) => setFormDescription(e.target.value)}
                  placeholder="Notes on batch syllabus pacing, cohort goals, or orientation details..."
                  className="w-full px-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                />
              </div>

              {/* Action Buttons */}
              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-6 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 transition flex items-center gap-2 shadow-lg shadow-purple-600/30"
                >
                  {saving ? (
                    <>
                      <div className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                      <span>Saving Batch...</span>
                    </>
                  ) : (
                    <span>{editingBatch ? 'Save Changes' : 'Create Batch'}</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 9. MODAL: ADD STUDENT TO BATCH */}
      {showAddStudentModal && selectedBatch && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-lg w-full shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>👥</span> Enroll Student into {selectedBatch.code}
              </h3>
              <button
                type="button"
                onClick={() => setShowAddStudentModal(false)}
                className="text-slate-400 hover:text-white text-sm p-1 rounded-lg hover:bg-slate-800 transition"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleAddStudentToBatch} className="space-y-4">
              {/* Searchable Student Finder */}
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Find Student (Search by Name, Email, Student ID, Phone) *
                </label>
                <div className="relative mb-2">
                  <span className="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-500 text-xs">
                    🔍
                  </span>
                  <input
                    type="text"
                    value={addStudentSearchQuery}
                    onChange={(e) => setAddStudentSearchQuery(e.target.value)}
                    placeholder="Type name, email, or STU-xxxx to filter..."
                    className="w-full pl-8 pr-8 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                  />
                  {addStudentSearchQuery && (
                    <button
                      type="button"
                      onClick={() => setAddStudentSearchQuery('')}
                      className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-white text-xs"
                    >
                      ✕
                    </button>
                  )}
                </div>

                {/* Student Selection Dropdown / List */}
                <select
                  value={addStudentId}
                  onChange={(e) => setAddStudentId(e.target.value)}
                  required
                  size={filteredAddStudents.length > 5 ? 5 : Math.max(3, filteredAddStudents.length + 1)}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="" disabled>-- Select a Student ({filteredAddStudents.length} matches) --</option>
                  {filteredAddStudents.map((s) => {
                    const isAlreadyEnrolled = selectedBatch.batch_students?.some(
                      (bs) => bs.user_id === s.id && bs.status === 'active'
                    )
                    return (
                      <option
                        key={s.id}
                        value={s.id}
                        disabled={Boolean(isAlreadyEnrolled)}
                        className={`py-1.5 ${isAlreadyEnrolled ? 'text-slate-500 bg-slate-900' : 'text-slate-200'}`}
                      >
                        {s.name} ({s.email}) {s.student_id ? `[${s.student_id}]` : ''} {isAlreadyEnrolled ? '— ⚠️ [Already Enrolled]' : ''}
                      </option>
                    )
                  })}
                </select>
              </div>

              {/* Selected Student Preview Card */}
              {addStudentId && (
                <div className="bg-purple-950/50 border border-purple-800/60 rounded-xl p-3 flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-purple-600 text-white font-bold flex items-center justify-center text-xs shrink-0">
                    {allStudents.find((s) => s.id === Number(addStudentId))?.name?.charAt(0) || 'S'}
                  </div>
                  <div className="text-xs">
                    <p className="font-bold text-white leading-tight">
                      {allStudents.find((s) => s.id === Number(addStudentId))?.name}
                    </p>
                    <p className="text-[11px] text-purple-300">
                      {allStudents.find((s) => s.id === Number(addStudentId))?.email}
                      {allStudents.find((s) => s.id === Number(addStudentId))?.student_id ? ` • ${allStudents.find((s) => s.id === Number(addStudentId))?.student_id}` : ''}
                    </p>
                  </div>
                </div>
              )}

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Admission Notes / Counseling Reference
                </label>
                <textarea
                  rows={2}
                  value={addStudentNotes}
                  onChange={(e) => setAddStudentNotes(e.target.value)}
                  placeholder="e.g. Regular batch admission via counseling interview..."
                  className="w-full px-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-purple-500 transition"
                />
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => {
                    setShowAddStudentModal(false)
                    setAddStudentSearchQuery('')
                  }}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !addStudentId}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 transition flex items-center gap-2"
                >
                  {saving ? (
                    <>
                      <div className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                      <span>Enrolling...</span>
                    </>
                  ) : (
                    <span>Confirm Enrollment</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 10. MODAL: TRANSFER STUDENT */}
      {showTransferModal && selectedBatch && selectedStudentMember && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-blue-900/60 rounded-3xl p-6 max-w-lg w-full shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>🔁</span> Transfer Student: {selectedStudentMember.user.name}
              </h3>
              <button
                type="button"
                onClick={() => setShowTransferModal(false)}
                className="text-slate-400 hover:text-white text-sm p-1 rounded-lg hover:bg-slate-800 transition"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleTransferStudentSubmit} className="space-y-4">
              <div className="bg-slate-950 p-3 rounded-xl border border-slate-800 text-xs">
                <span className="text-slate-400">Current Cohort: </span>
                <strong className="text-purple-300 font-mono">{selectedBatch.code}</strong>
                <span className="text-slate-500"> ({selectedBatch.name})</span>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Select Destination Batch Cohort *
                </label>
                <select
                  value={transferTargetBatchId}
                  onChange={(e) => setTransferTargetBatchId(e.target.value)}
                  required
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-blue-500 transition"
                >
                  <option value="">-- Choose Target Batch --</option>
                  {batches
                    .filter((b) => b.id !== selectedBatch.id)
                    .map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.code} — {b.name} ({b.course?.title})
                      </option>
                    ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Transfer Reason / Approval Justification *
                </label>
                <textarea
                  rows={3}
                  value={transferReason}
                  onChange={(e) => setTransferReason(e.target.value)}
                  required
                  placeholder="e.g. Student requested evening batch due to office shift timings..."
                  className="w-full px-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-blue-500 transition"
                />
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowTransferModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !transferTargetBatchId || !transferReason.trim()}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 transition flex items-center gap-2 shadow-lg shadow-blue-600/30"
                >
                  {saving ? (
                    <>
                      <div className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                      <span>Executing Transfer...</span>
                    </>
                  ) : (
                    <span>Confirm Transfer</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 11. MODAL: DISCONTINUE STUDENT */}
      {showDiscontinueModal && selectedBatch && selectedStudentMember && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-amber-900/60 rounded-3xl p-6 max-w-lg w-full shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>⏸️</span> Mark Discontinued: {selectedStudentMember.user.name}
              </h3>
              <button
                type="button"
                onClick={() => setShowDiscontinueModal(false)}
                className="text-slate-400 hover:text-white text-sm p-1 rounded-lg hover:bg-slate-800 transition"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleDiscontinueStudentSubmit} className="space-y-4">
              <p className="text-xs text-slate-300 leading-relaxed">
                The student's status in <strong className="text-white font-mono">{selectedBatch.code}</strong> will be marked as <strong>Discontinued</strong> while keeping their historical record intact.
              </p>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Discontinuation Reason *
                </label>
                <textarea
                  rows={3}
                  value={discontinueReason}
                  onChange={(e) => setDiscontinueReason(e.target.value)}
                  required
                  placeholder="e.g. Medical leave, college exams, or personal break..."
                  className="w-full px-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-amber-500 transition"
                />
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowDiscontinueModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !discontinueReason.trim()}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-amber-600 hover:bg-amber-500 disabled:opacity-50 transition flex items-center gap-2 shadow-lg shadow-amber-600/30"
                >
                  {saving ? (
                    <>
                      <div className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                      <span>Saving...</span>
                    </>
                  ) : (
                    <span>Confirm Discontinuation</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 12. MODAL: REJOIN STUDENT */}
      {showRejoinModal && selectedBatch && selectedStudentMember && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-emerald-900/60 rounded-3xl p-6 max-w-lg w-full shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>▶️</span> Rejoin Cohort: {selectedStudentMember.user.name}
              </h3>
              <button
                type="button"
                onClick={() => setShowRejoinModal(false)}
                className="text-slate-400 hover:text-white text-sm p-1 rounded-lg hover:bg-slate-800 transition"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleRejoinStudentSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Target Cohort to Rejoin
                </label>
                <select
                  value={rejoinTargetBatchId}
                  onChange={(e) => setRejoinTargetBatchId(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-emerald-500 transition"
                >
                  <option value={selectedBatch.id}>Rejoin Current Batch ({selectedBatch.code})</option>
                  {batches
                    .filter((b) => b.id !== selectedBatch.id)
                    .map((b) => (
                      <option key={b.id} value={b.id}>
                        Join New Cohort: {b.code} ({b.name})
                      </option>
                    ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Rejoin Reason / Counseling Notes
                </label>
                <textarea
                  rows={3}
                  value={rejoinReason}
                  onChange={(e) => setRejoinReason(e.target.value)}
                  placeholder="e.g. Resumed after exam break, approved for re-entry..."
                  className="w-full px-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-emerald-500 transition"
                />
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowRejoinModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 transition flex items-center gap-2 shadow-lg shadow-emerald-600/30"
                >
                  {saving ? (
                    <>
                      <div className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                      <span>Processing Rejoin...</span>
                    </>
                  ) : (
                    <span>Confirm Rejoin</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
