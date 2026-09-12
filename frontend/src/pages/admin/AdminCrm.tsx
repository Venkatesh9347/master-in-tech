import { useEffect, useState, useCallback, useMemo } from 'react'
import API from '../../services/api'
import { useAuth } from '../../context/useAuth'
import type { Course } from '../../types/course'

export type LeadStatus =
  | 'new'
  | 'contacted'
  | 'demo_scheduled'
  | 'demo_completed'
  | 'interested'
  | 'follow_up'
  | 'payment_pending'
  | 'admission_confirmed'
  | 'enrolled'
  | 'converted'
  | 'not_interested'
  | 'lost'
  | 'no_response'
  | 'closed'

export type LeadPriority = 'hot' | 'warm' | 'cold' | 'high' | 'medium' | 'low'

export interface CounsellorUser {
  id: number
  name: string
  email: string
  avatar?: string | null
  role: string
  phone?: string | null
}

export interface CrmActivityItem {
  id: number
  enquiry_id: number
  user_id?: number | null
  activity_type: 'note' | 'call' | 'follow_up' | 'demo_scheduled' | 'demo_completed' | 'status_change' | 'payment_event' | 'conversion'
  title: string
  description?: string | null
  metadata?: Record<string, unknown> | null
  created_at: string
  user?: { id: number; name: string; email: string; avatar?: string | null } | null
}

export interface CrmFollowUpItem {
  id: number
  enquiry_id: number
  assigned_to?: number | null
  created_by?: number | null
  scheduled_at: string
  status: 'pending' | 'completed' | 'cancelled' | 'overdue'
  title: string
  notes?: string | null
  outcome?: string | null
  completed_at?: string | null
  created_at: string
  assignedTo?: { id: number; name: string; email: string; avatar?: string | null } | null
  createdBy?: { id: number; name: string; email: string } | null
  enquiry?: {
    id: number
    name: string
    email: string
    phone: string
    course_title?: string | null
    status: LeadStatus
    priority?: string | null
    city?: string | null
  } | null
}

export interface LeadItem {
  id: number
  name: string
  email: string
  phone: string
  source: string
  priority: LeadPriority
  course_id?: number | null
  course_title?: string | null
  preferred_time?: string | null
  message?: string | null
  qualification?: string | null
  experience_level?: string | null
  city?: string | null
  status: LeadStatus
  demo_date?: string | null
  demo_time?: string | null
  demo_outcome?: string | null
  assigned_agent?: string | null
  assigned_counsellor_id?: number | null
  next_follow_up_date?: string | null
  next_follow_up_time?: string | null
  expected_revenue?: number | string | null
  amount_paid?: number | string | null
  payment_status?: 'unpaid' | 'partial' | 'paid' | null
  lost_reason?: string | null
  enrolled_user_id?: number | null
  enrolled_at?: string | null
  created_at: string
  updated_at: string
  course?: { id: number; title: string; code?: string; category?: string; price?: number; slug?: string; thumbnail?: string } | null
  assignedCounsellor?: CounsellorUser | null
  user?: { id: number; name: string; email: string; student_id?: string | null } | null
  enrolledUser?: { id: number; name: string; email: string; student_id?: string | null } | null
  activities_count?: number
  follow_ups_count?: number
  activities?: CrmActivityItem[]
  followUps?: CrmFollowUpItem[]
  latestFollowUp?: CrmFollowUpItem | null
}

export interface CrmStats {
  total_leads: number
  new_leads: number
  contacted: number
  follow_ups_due: number
  demos: number
  interested: number
  payment_pending: number
  converted: number
  lost: number
  overdue_follow_ups: number
  todays_follow_ups: number
  upcoming_follow_ups: number
  completed_follow_ups: number
  conversion_rate: number
  source_breakdown?: Record<string, number>
  priority_breakdown?: Record<string, number>
}

interface BatchOption {
  id: number
  name: string
  code: string
  course_id: number
  status: string
  start_date: string
  schedule_time?: string | null
  max_students?: number | null
  active_batch_students_count?: number
}

const statusBadgeStyles: Record<string, string> = {
  new: 'bg-blue-950/80 text-blue-300 border-blue-700',
  contacted: 'bg-amber-950/80 text-amber-300 border-amber-700',
  demo_scheduled: 'bg-purple-950/80 text-purple-300 border-purple-700',
  demo_completed: 'bg-indigo-950/80 text-indigo-300 border-indigo-700',
  interested: 'bg-cyan-950/80 text-cyan-300 border-cyan-700',
  follow_up: 'bg-orange-950/80 text-orange-300 border-orange-700',
  payment_pending: 'bg-yellow-950/80 text-yellow-300 border-yellow-700',
  admission_confirmed: 'bg-teal-950/80 text-teal-300 border-teal-600',
  enrolled: 'bg-emerald-950/80 text-emerald-300 border-emerald-600',
  converted: 'bg-emerald-950/80 text-emerald-300 border-emerald-600',
  not_interested: 'bg-rose-950/80 text-rose-400 border-rose-800',
  lost: 'bg-rose-950/80 text-rose-400 border-rose-800',
  no_response: 'bg-slate-800 text-slate-400 border-slate-700',
  closed: 'bg-slate-800 text-slate-400 border-slate-700',
}

const priorityBadgeStyles: Record<string, { bg: string; label: string; icon: string }> = {
  hot: { bg: 'bg-rose-950/80 text-rose-300 border-rose-800', label: 'Hot', icon: '🔥' },
  high: { bg: 'bg-rose-950/80 text-rose-300 border-rose-800', label: 'High', icon: '🔥' },
  warm: { bg: 'bg-amber-950/80 text-amber-300 border-amber-800', label: 'Warm', icon: '⚡' },
  medium: { bg: 'bg-amber-950/80 text-amber-300 border-amber-800', label: 'Medium', icon: '⚡' },
  cold: { bg: 'bg-blue-950/80 text-blue-300 border-blue-800', label: 'Cold', icon: '❄️' },
  low: { bg: 'bg-blue-950/80 text-blue-300 border-blue-800', label: 'Low', icon: '❄️' },
}

export default function AdminCrm() {
  const { user } = useAuth()
  // Main Tab: 'leads_roster' | 'follow_ups_desk'
  const [activeTab, setActiveTab] = useState<'leads_roster' | 'follow_ups_desk'>('leads_roster')

  // Global Data
  const [stats, setStats] = useState<CrmStats | null>(null)
  const [leads, setLeads] = useState<LeadItem[]>([])
  const [courses, setCourses] = useState<Course[]>([])
  const [batches, setBatches] = useState<BatchOption[]>([])
  const [counsellors, setCounsellors] = useState<CounsellorUser[]>([])
  const [followUpsList, setFollowUpsList] = useState<CrmFollowUpItem[]>([])

  // Filters
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [priorityFilter, setPriorityFilter] = useState('all')
  const [sourceFilter, setSourceFilter] = useState('all')
  const [courseFilter, setCourseFilter] = useState('all')
  const [counsellorFilter, setCounsellorFilter] = useState('all')
  const [followUpFilter, setFollowUpFilter] = useState<'all' | 'overdue' | 'today' | 'upcoming'>('all')

  // Follow-ups Desk sub-filter
  const [followUpDeskTab, setFollowUpDeskTab] = useState<'overdue' | 'today' | 'upcoming' | 'completed' | 'all'>('overdue')

  // Loading & State
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  // Modals & Drawer State
  const [selectedLead, setSelectedLead] = useState<LeadItem | null>(null)
  const [loadingLeadDetail, setLoadingLeadDetail] = useState(false)
  const [showDrawer, setShowDrawer] = useState(false)

  // Drawer Form State
  const [drawerAction, setDrawerAction] = useState<'timeline' | 'call' | 'note' | 'follow_up' | 'status'>('timeline')
  const [callTitle, setCallTitle] = useState('')
  const [callDuration, setCallDuration] = useState('5 mins')
  const [callOutcome, setCallOutcome] = useState('interested')
  const [callNotes, setCallNotes] = useState('')
  const [generalNote, setGeneralNote] = useState('')
  const [scheduleFollowUpDate, setScheduleFollowUpDate] = useState('')
  const [scheduleFollowUpTitle, setScheduleFollowUpTitle] = useState('Call to discuss batch timings')
  const [scheduleFollowUpNotes, setScheduleFollowUpNotes] = useState('')
  const [newStatusValue, setNewStatusValue] = useState<string>('contacted')
  const [newPriorityValue, setNewPriorityValue] = useState<string>('hot')

  // Lead Create/Edit Modal
  const [showLeadModal, setShowLeadModal] = useState(false)
  const [editingLead, setEditingLead] = useState<LeadItem | null>(null)
  const [formName, setFormName] = useState('')
  const [formEmail, setFormEmail] = useState('')
  const [formPhone, setFormPhone] = useState('')
  const [formSource, setFormSource] = useState('website')
  const [formPriority, setFormPriority] = useState<LeadPriority>('medium')
  const [formCourseId, setFormCourseId] = useState<string | number>('')
  const [formCounsellorId, setFormCounsellorId] = useState<string | number>('')
  const [formCity, setFormCity] = useState('')
  const [formQualification, setFormQualification] = useState('')
  const [formExperienceLevel, setFormExperienceLevel] = useState('fresher')
  const [formExpectedRevenue, setFormExpectedRevenue] = useState<string | number>('')
  const [formNextFollowUpDate, setFormNextFollowUpDate] = useState('')
  const [formMessage, setFormMessage] = useState('')

  // Conversion Modal State
  const [showConvertModal, setShowConvertModal] = useState(false)
  const [convertLead, setConvertLead] = useState<LeadItem | null>(null)
  const [convertCourseId, setConvertCourseId] = useState<string | number>('')
  const [convertBatchId, setConvertBatchId] = useState<string | number>('')
  const [convertAmountPaid, setConvertAmountPaid] = useState<number | string>('')
  const [convertPaymentMode, setConvertPaymentMode] = useState('upi')
  const [convertTransactionId, setConvertTransactionId] = useState('')
  const [convertNotes, setConvertNotes] = useState('')

  // Follow-up Complete Modal
  const [completingFollowUp, setCompletingFollowUp] = useState<CrmFollowUpItem | null>(null)
  const [followUpOutcomeText, setFollowUpOutcomeText] = useState('')

  // 1. Fetch KPI Stats
  const fetchStats = useCallback(async () => {
    try {
      const res = await API.get<CrmStats>('/admin/crm/stats')
      setStats(res.data)
    } catch {
      // Non-blocking
    }
  }, [])

  // 2. Fetch Leads List
  const fetchLeads = useCallback(async () => {
    setLoading(true)
    setErrorMsg('')
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (statusFilter !== 'all') params.append('status', statusFilter)
      if (priorityFilter !== 'all') params.append('priority', priorityFilter)
      if (sourceFilter !== 'all') params.append('source', sourceFilter)
      if (courseFilter !== 'all') params.append('course_id', courseFilter)
      if (counsellorFilter !== 'all') params.append('assigned_counsellor_id', counsellorFilter)
      if (followUpFilter !== 'all') params.append('follow_up_filter', followUpFilter)

      const res = await API.get<{ data?: LeadItem[] } | LeadItem[]>(`/admin/crm/leads?${params.toString()}`)
      const items = Array.isArray(res.data) ? res.data : res.data?.data || []
      setLeads(items)
    } catch {
      setErrorMsg('Failed to load leads roster.')
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter, priorityFilter, sourceFilter, courseFilter, counsellorFilter, followUpFilter])

  // 3. Fetch Follow-ups Desk List
  const fetchFollowUps = useCallback(async () => {
    try {
      const params = new URLSearchParams()
      if (followUpDeskTab !== 'all') params.append('filter', followUpDeskTab)
      const res = await API.get<CrmFollowUpItem[]>(`/admin/crm/follow-ups?${params.toString()}`)
      setFollowUpsList(Array.isArray(res.data) ? res.data : [])
    } catch {
      // Non-blocking
    }
  }, [followUpDeskTab])

  // 4. Fetch Single Lead Details & Timeline
  const fetchLeadDetail = useCallback(async (leadId: number) => {
    setLoadingLeadDetail(true)
    try {
      const res = await API.get<LeadItem>(`/admin/crm/leads/${leadId}`)
      setSelectedLead(res.data)
      setNewStatusValue(res.data.status)
      setNewPriorityValue(res.data.priority || 'medium')
    } catch {
      setErrorMsg('Failed to load lead details and timeline.')
    } finally {
      setLoadingLeadDetail(false)
    }
  }, [])

  // Initial Load
  useEffect(() => {
    fetchStats()
    fetchLeads()

    API.get<Course[]>('/courses')
      .then((res) => setCourses(Array.isArray(res.data) ? res.data : []))
      .catch(() => {})

    API.get<BatchOption[]>('/admin/batches')
      .then((res) => setBatches(Array.isArray(res.data) ? res.data : []))
      .catch(() => {})

    API.get<CounsellorUser[]>('/admin/crm/counsellors')
      .then((res) => setCounsellors(Array.isArray(res.data) ? res.data : []))
      .catch(() => {})
  }, [fetchStats, fetchLeads])

  useEffect(() => {
    if (activeTab === 'follow_ups_desk') {
      fetchFollowUps()
    }
  }, [activeTab, fetchFollowUps])

  // Reset Lead Form
  const openCreateLeadModal = () => {
    setEditingLead(null)
    setFormName('')
    setFormEmail('')
    setFormPhone('')
    setFormSource('website')
    setFormPriority('medium')
    setFormCourseId(courses.length > 0 ? courses[0].id : '')
    setFormCounsellorId(counsellors.length > 0 ? counsellors[0].id : '')
    setFormCity('')
    setFormQualification('')
    setFormExperienceLevel('fresher')
    setFormExpectedRevenue('')
    setFormNextFollowUpDate(new Date(Date.now() + 86400000).toISOString().split('T')[0])
    setFormMessage('')
    setShowLeadModal(true)
  }

  const openEditLeadModal = (lead: LeadItem) => {
    setEditingLead(lead)
    setFormName(lead.name)
    setFormEmail(lead.email)
    setFormPhone(lead.phone)
    setFormSource(lead.source || 'website')
    setFormPriority(lead.priority || 'medium')
    setFormCourseId(lead.course_id || '')
    setFormCounsellorId(lead.assigned_counsellor_id || '')
    setFormCity(lead.city || '')
    setFormQualification(lead.qualification || '')
    setFormExperienceLevel(lead.experience_level || 'fresher')
    setFormExpectedRevenue(lead.expected_revenue || '')
    setFormNextFollowUpDate(lead.next_follow_up_date ? lead.next_follow_up_date.split('T')[0] : '')
    setFormMessage(lead.message || '')
    setShowLeadModal(true)
  }

  // Handle Save Lead
  const handleSaveLead = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = {
      name: formName.trim(),
      email: formEmail.trim().toLowerCase(),
      phone: formPhone.trim(),
      source: formSource,
      priority: formPriority,
      course_id: formCourseId ? Number(formCourseId) : null,
      assigned_counsellor_id: formCounsellorId ? Number(formCounsellorId) : null,
      city: formCity.trim() || null,
      qualification: formQualification.trim() || null,
      experience_level: formExperienceLevel || null,
      expected_revenue: formExpectedRevenue ? Number(formExpectedRevenue) : null,
      next_follow_up_date: formNextFollowUpDate || null,
      message: formMessage.trim() || null,
    }

    try {
      if (editingLead) {
        await API.put(`/admin/crm/leads/${editingLead.id}`, payload)
        setSuccessMsg(`✓ Lead for ${payload.name} updated successfully!`)
      } else {
        const res = await API.post<{ message: string; lead: LeadItem }>('/admin/crm/leads', payload)
        setSuccessMsg(`✓ Lead for ${res.data.lead.name} created successfully!`)
      }
      setShowLeadModal(false)
      fetchLeads()
      fetchStats()
      if (selectedLead && editingLead && selectedLead.id === editingLead.id) {
        fetchLeadDetail(selectedLead.id)
      }
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to save lead.')
    } finally {
      setSaving(false)
    }
  }

  // Handle Claim Lead (assign unassigned lead to self)
  const handleClaimLead = async () => {
    if (!selectedLead || !user) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.put(`/admin/crm/leads/${selectedLead.id}`, {
        assigned_counsellor_id: user.id,
      })
      setSuccessMsg(`✓ Lead claimed — now assigned to you.`)
      fetchLeads()
      fetchStats()
      fetchLeadDetail(selectedLead.id)
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { status?: number; data?: { message?: string } } }
      setErrorMsg(
        resp.response?.status === 403
          ? 'You do not have permission to claim this lead.'
          : resp.response?.data?.message || 'Failed to claim lead.'
      )
    } finally {
      setSaving(false)
    }
  }

  // Handle Log Outbound Call
  const handleLogCall = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedLead) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(`/admin/crm/leads/${selectedLead.id}/activities`, {
        activity_type: 'call',
        title: callTitle.trim() || `Call: ${callOutcome.toUpperCase()} (${callDuration})`,
        description: callNotes.trim() || undefined,
        metadata: {
          duration: callDuration,
          outcome: callOutcome,
        },
      })
      setSuccessMsg('✓ Call activity logged on timeline!')
      setCallTitle('')
      setCallNotes('')
      fetchLeadDetail(selectedLead.id)
      fetchStats()
      setDrawerAction('timeline')
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to log call.')
    } finally {
      setSaving(false)
    }
  }

  // Handle Add General Note
  const handleAddNote = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedLead || !generalNote.trim()) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(`/admin/crm/leads/${selectedLead.id}/activities`, {
        activity_type: 'note',
        title: 'Counsellor Note Added',
        description: generalNote.trim(),
      })
      setSuccessMsg('✓ Note added to lead timeline!')
      setGeneralNote('')
      fetchLeadDetail(selectedLead.id)
      setDrawerAction('timeline')
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to add note.')
    } finally {
      setSaving(false)
    }
  }

  // Handle Schedule Follow-up
  const handleScheduleFollowUp = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedLead || !scheduleFollowUpDate) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.post(`/admin/crm/leads/${selectedLead.id}/follow-ups`, {
        scheduled_at: scheduleFollowUpDate,
        title: scheduleFollowUpTitle.trim() || 'Follow-up Call',
        notes: scheduleFollowUpNotes.trim() || undefined,
      })
      setSuccessMsg(`✓ Follow-up scheduled for ${new Date(scheduleFollowUpDate).toLocaleString()}!`)
      setScheduleFollowUpDate('')
      setScheduleFollowUpNotes('')
      fetchLeadDetail(selectedLead.id)
      fetchLeads()
      fetchStats()
      setDrawerAction('timeline')
      setTimeout(() => setSuccessMsg(''), 5000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to schedule follow-up.')
    } finally {
      setSaving(false)
    }
  }

  // Handle Update Status & Priority in Drawer
  const handleUpdateStatusAndPriority = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedLead) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.put(`/admin/crm/leads/${selectedLead.id}`, {
        status: newStatusValue,
        priority: newPriorityValue,
      })
      setSuccessMsg(`✓ Status updated to ${newStatusValue.toUpperCase()} & Priority to ${newPriorityValue.toUpperCase()}!`)
      fetchLeadDetail(selectedLead.id)
      fetchLeads()
      fetchStats()
      setDrawerAction('timeline')
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to update status.')
    } finally {
      setSaving(false)
    }
  }

  // Handle Complete Follow-up
  const handleCompleteFollowUpSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!completingFollowUp) return
    setSaving(true)
    setErrorMsg('')
    try {
      await API.put(`/admin/crm/follow-ups/${completingFollowUp.id}`, {
        status: 'completed',
        outcome: followUpOutcomeText.trim() || 'Follow-up successfully completed.',
      })
      setSuccessMsg('✓ Follow-up marked as completed!')
      setCompletingFollowUp(null)
      setFollowUpOutcomeText('')
      fetchFollowUps()
      fetchStats()
      if (selectedLead) fetchLeadDetail(selectedLead.id)
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to complete follow-up.')
    } finally {
      setSaving(false)
    }
  }

  // Open Conversion Modal
  const openConversionModal = (lead: LeadItem) => {
    setConvertLead(lead)
    setConvertCourseId(lead.course_id || (courses.length > 0 ? courses[0].id : ''))
    setConvertBatchId('')
    setConvertAmountPaid(lead.amount_paid || '')
    setConvertPaymentMode('upi')
    setConvertTransactionId('')
    setConvertNotes('Admitted and enrolled via CRM')
    setShowConvertModal(true)
  }

  // Filtered Batches for Selected Course in Conversion Modal
  const availableBatchesForConversion = useMemo(() => {
    if (!convertCourseId) return batches
    return batches.filter((b) => b.course_id === Number(convertCourseId))
  }, [batches, convertCourseId])

  // Handle CRM -> LMS Conversion Submit
  const handleConvertLeadSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!convertLead || !convertCourseId) return
    setSaving(true)
    setErrorMsg('')
    try {
      const res = await API.post<{ message: string; user: { name: string; student_id: string } }>(
        `/admin/crm/leads/${convertLead.id}/convert`,
        {
          course_id: Number(convertCourseId),
          batch_id: convertBatchId ? Number(convertBatchId) : null,
          name: convertLead.name,
          email: convertLead.email,
          phone: convertLead.phone,
          amount_paid: convertAmountPaid ? Number(convertAmountPaid) : 0,
          payment_mode: convertPaymentMode,
          transaction_id: convertTransactionId.trim() || null,
          notes: convertNotes.trim() || null,
        }
      )

      setSuccessMsg(`🎉 Success! ${res.data.user.name} (${res.data.user.student_id}) enrolled with active LMS classroom access!`)
      setShowConvertModal(false)
      setConvertLead(null)
      fetchLeads()
      fetchStats()
      if (selectedLead && selectedLead.id === convertLead.id) {
        fetchLeadDetail(selectedLead.id)
      }
      setTimeout(() => setSuccessMsg(''), 7000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to convert lead to student.')
    } finally {
      setSaving(false)
    }
  }

  // Delete Lead
  const handleDeleteLead = async (lead: LeadItem) => {
    if (!window.confirm(`Delete lead record for "${lead.name}" (${lead.email})?`)) return
    try {
      await API.delete(`/admin/crm/leads/${lead.id}`)
      setSuccessMsg(`✓ Lead ${lead.name} deleted.`)
      fetchLeads()
      fetchStats()
      if (selectedLead && selectedLead.id === lead.id) {
        setShowDrawer(false)
        setSelectedLead(null)
      }
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to delete lead.')
    }
  }

  return (
    <div className="space-y-8">
      {/* 1. Header Banner */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center gap-2.5">
            <span className="p-2 rounded-xl bg-purple-950/80 border border-purple-800 text-purple-400 text-xl shadow-inner">
              🎯
            </span>
            CRM & Admissions Pipeline
          </h1>
          <p className="text-slate-400 text-sm mt-1">
            End-to-end lead management, activity timelines, outbound calls, scheduled follow-ups, and one-click CRM → LMS cohort conversion.
          </p>
        </div>

        <div className="flex items-center gap-3 self-start md:self-auto flex-wrap">
          <button
            type="button"
            onClick={openCreateLeadModal}
            className="px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-2"
          >
            <span>➕</span>
            <span>Add New Lead</span>
          </button>

          <button
            type="button"
            onClick={() => {
              fetchStats()
              fetchLeads()
              if (activeTab === 'follow_ups_desk') fetchFollowUps()
            }}
            className="px-3.5 py-2.5 rounded-xl text-xs font-bold text-slate-300 bg-slate-950 hover:bg-slate-900 border border-slate-800 hover:text-white transition flex items-center gap-1.5"
          >
            <span>🔄</span>
            <span>Refresh</span>
          </button>
        </div>
      </div>

      {/* 2. Platform KPI Metrics Bar (9 Pipeline Stages + Conversion Rate) */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-9 gap-3">
        {/* Total Leads */}
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm hover:border-purple-500/50 transition">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Total Leads</span>
          <p className="text-2xl font-black text-white mt-1">{stats?.total_leads ?? leads.length}</p>
          <span className="text-[10px] font-semibold text-purple-300 mt-0.5 block">
            {stats?.conversion_rate ?? 0}% Rate
          </span>
        </div>

        {/* New */}
        <div
          onClick={() => { setStatusFilter('new'); setActiveTab('leads_roster') }}
          className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm hover:border-blue-500/50 transition cursor-pointer"
        >
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">New Leads</span>
          <p className="text-2xl font-black text-blue-400 mt-1">{stats?.new_leads ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Uncontacted</span>
        </div>

        {/* Contacted */}
        <div
          onClick={() => { setStatusFilter('contacted'); setActiveTab('leads_roster') }}
          className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm hover:border-amber-500/50 transition cursor-pointer"
        >
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Contacted</span>
          <p className="text-2xl font-black text-amber-400 mt-1">{stats?.contacted ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">In Dialogue</span>
        </div>

        {/* Follow-ups Due */}
        <div
          onClick={() => { setActiveTab('follow_ups_desk'); setFollowUpDeskTab('overdue') }}
          className={`bg-slate-950/80 backdrop-blur border rounded-2xl p-4 shadow-sm transition cursor-pointer ${
            (stats?.overdue_follow_ups ?? 0) > 0 ? 'border-rose-700/80 hover:border-rose-500' : 'border-slate-800 hover:border-orange-500'
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Follow-ups Due</span>
            {(stats?.overdue_follow_ups ?? 0) > 0 && (
              <span className="text-[9px] font-black px-1.5 py-0.5 rounded bg-rose-900 text-rose-300">
                {stats?.overdue_follow_ups} Overdue
              </span>
            )}
          </div>
          <p className="text-2xl font-black text-orange-400 mt-1">{stats?.follow_ups_due ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Today & Overdue</span>
        </div>

        {/* Demos */}
        <div
          onClick={() => { setStatusFilter('demo_scheduled'); setActiveTab('leads_roster') }}
          className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm hover:border-purple-500/50 transition cursor-pointer"
        >
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Demos</span>
          <p className="text-2xl font-black text-purple-400 mt-1">{stats?.demos ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Live Sessions</span>
        </div>

        {/* Interested */}
        <div
          onClick={() => { setStatusFilter('interested'); setActiveTab('leads_roster') }}
          className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm hover:border-cyan-500/50 transition cursor-pointer"
        >
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Interested</span>
          <p className="text-2xl font-black text-cyan-400 mt-1">{stats?.interested ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Qualified</span>
        </div>

        {/* Payment Pending */}
        <div
          onClick={() => { setStatusFilter('payment_pending'); setActiveTab('leads_roster') }}
          className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm hover:border-yellow-500/50 transition cursor-pointer"
        >
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Pay Pending</span>
          <p className="text-2xl font-black text-yellow-400 mt-1">{stats?.payment_pending ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Awaiting Fees</span>
        </div>

        {/* Converted */}
        <div
          onClick={() => { setStatusFilter('converted'); setActiveTab('leads_roster') }}
          className="bg-slate-950/80 backdrop-blur border border-emerald-900/60 rounded-2xl p-4 shadow-sm hover:border-emerald-500 transition cursor-pointer"
        >
          <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-400 block">Converted</span>
          <p className="text-2xl font-black text-emerald-400 mt-1">{stats?.converted ?? '...'}</p>
          <span className="text-[10px] font-semibold text-emerald-300 mt-0.5 block">Enrolled in LMS</span>
        </div>

        {/* Lost */}
        <div
          onClick={() => { setStatusFilter('lost'); setActiveTab('leads_roster') }}
          className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-2xl p-4 shadow-sm hover:border-rose-500/50 transition cursor-pointer"
        >
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Lost</span>
          <p className="text-2xl font-black text-rose-400 mt-1">{stats?.lost ?? '...'}</p>
          <span className="text-[10px] font-semibold text-slate-400 mt-0.5 block">Closed / Dropped</span>
        </div>
      </div>

      {/* Alerts */}
      {successMsg && (
        <div className="bg-emerald-950/80 border border-emerald-800 text-emerald-200 px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg">
          <span>{successMsg}</span>
          <button type="button" onClick={() => setSuccessMsg('')} className="text-emerald-400 hover:text-white">✕</button>
        </div>
      )}

      {errorMsg && (
        <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg">
          <span>{errorMsg}</span>
          <button type="button" onClick={() => setErrorMsg('')} className="text-rose-400 hover:text-white">✕</button>
        </div>
      )}

      {/* 3. Navigation Tabs (Leads Roster vs Follow-ups Desk) */}
      <div className="flex items-center gap-3 border-b border-slate-800 pb-2">
        <button
          type="button"
          onClick={() => setActiveTab('leads_roster')}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
            activeTab === 'leads_roster'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>📋</span>
          <span>Leads Roster & Pipeline ({leads.length})</span>
        </button>

        <button
          type="button"
          onClick={() => {
            setActiveTab('follow_ups_desk')
            fetchFollowUps()
          }}
          className={`px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center gap-2 ${
            activeTab === 'follow_ups_desk'
              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-900'
          }`}
        >
          <span>⏰</span>
          <span>Follow-ups Desk</span>
          {(stats?.overdue_follow_ups ?? 0) > 0 && (
            <span className="px-1.5 py-0.5 rounded-full text-[10px] font-black bg-rose-600 text-white">
              {stats?.overdue_follow_ups}
            </span>
          )}
        </button>
      </div>

      {/* 4. TAB A: LEADS ROSTER */}
      {activeTab === 'leads_roster' && (
        <div className="space-y-5">
          {/* Filters & Search Deck */}
          <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-5 shadow-xl space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
              {/* Search Bar */}
              <div className="lg:col-span-4 relative">
                <span className="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500 text-xs">
                  🔍
                </span>
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search candidate name, email, phone, city, course..."
                  className="w-full pl-9 pr-8 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
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

              {/* Status Filter */}
              <div className="lg:col-span-2">
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Pipeline Stages</option>
                  <option value="new">New Leads</option>
                  <option value="contacted">Contacted</option>
                  <option value="interested">Interested</option>
                  <option value="demo_scheduled">Demo Scheduled</option>
                  <option value="demo_completed">Demo Completed</option>
                  <option value="follow_up">Follow-up Needed</option>
                  <option value="payment_pending">Payment Pending</option>
                  <option value="admission_confirmed">Admission Confirmed</option>
                  <option value="converted">Converted (Enrolled)</option>
                  <option value="lost">Lost / Dropped</option>
                </select>
              </div>

              {/* Priority Filter */}
              <div className="lg:col-span-2">
                <select
                  value={priorityFilter}
                  onChange={(e) => setPriorityFilter(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Priorities</option>
                  <option value="hot">🔥 Hot Priority</option>
                  <option value="warm">⚡ Warm Priority</option>
                  <option value="cold">❄️ Cold Priority</option>
                </select>
              </div>

              {/* Lead Source Filter */}
              <div className="lg:col-span-2">
                <select
                  value={sourceFilter}
                  onChange={(e) => setSourceFilter(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Lead Sources</option>
                  <option value="website">Website Portal</option>
                  <option value="google_ads">Google Ads</option>
                  <option value="social_media">Social Media</option>
                  <option value="referral">Student Referral</option>
                  <option value="direct_call">Direct Call</option>
                  <option value="event">Webinar / Event</option>
                  <option value="walk_in">Walk-In Candidate</option>
                </select>
              </div>

              {/* Course Filter */}
              <div className="lg:col-span-2">
                <select
                  value={courseFilter}
                  onChange={(e) => setCourseFilter(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Courses</option>
                  {courses.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.title}
                    </option>
                  ))}
                </select>
              </div>

              {/* Counsellor Filter */}
              <div className="lg:col-span-2">
                <select
                  value={counsellorFilter}
                  onChange={(e) => setCounsellorFilter(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-2xl text-xs font-semibold text-white focus:outline-none focus:border-purple-500 transition"
                >
                  <option value="all">All Counsellors</option>
                  {counsellors.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* Quick Follow-up Horizon Chips */}
            <div className="flex items-center gap-2 border-t border-slate-800/80 pt-3 flex-wrap">
              <span className="text-[11px] font-bold text-slate-400 mr-1">Follow-up Filter:</span>
              <button
                type="button"
                onClick={() => setFollowUpFilter('all')}
                className={`px-2.5 py-1 rounded-xl text-xs font-bold transition ${
                  followUpFilter === 'all' ? 'bg-purple-600 text-white shadow-xs' : 'bg-slate-900 text-slate-400 hover:text-white'
                }`}
              >
                All Timeframes
              </button>

              <button
                type="button"
                onClick={() => setFollowUpFilter('overdue')}
                className={`px-2.5 py-1 rounded-xl text-xs font-bold transition flex items-center gap-1 ${
                  followUpFilter === 'overdue' ? 'bg-rose-600 text-white shadow-xs' : 'bg-slate-900 text-rose-400 hover:bg-rose-950'
                }`}
              >
                <span>🚨</span>
                <span>Overdue ({stats?.overdue_follow_ups ?? 0})</span>
              </button>

              <button
                type="button"
                onClick={() => setFollowUpFilter('today')}
                className={`px-2.5 py-1 rounded-xl text-xs font-bold transition flex items-center gap-1 ${
                  followUpFilter === 'today' ? 'bg-cyan-600 text-white shadow-xs' : 'bg-slate-900 text-cyan-400 hover:bg-cyan-950'
                }`}
              >
                <span>📅</span>
                <span>Due Today ({stats?.todays_follow_ups ?? 0})</span>
              </button>

              <button
                type="button"
                onClick={() => setFollowUpFilter('upcoming')}
                className={`px-2.5 py-1 rounded-xl text-xs font-bold transition flex items-center gap-1 ${
                  followUpFilter === 'upcoming' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-900 text-blue-400 hover:bg-blue-950'
                }`}
              >
                <span>⏳</span>
                <span>Upcoming ({stats?.upcoming_follow_ups ?? 0})</span>
              </button>
            </div>
          </div>

          {/* Leads Table */}
          {loading ? (
            <div className="py-20 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
              <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
              <span>Loading leads roster...</span>
            </div>
          ) : leads.length === 0 ? (
            <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-16 text-center text-slate-500 shadow-xl space-y-3">
              <span className="text-4xl">📭</span>
              <h3 className="font-extrabold text-base text-slate-300">No Leads Found</h3>
              <p className="text-xs text-slate-400 max-w-md mx-auto">
                No prospective student lead matched your current filter criteria. Click "+ Add New Lead" above to create one.
              </p>
            </div>
          ) : (
            <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl overflow-hidden shadow-xl">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                    <tr>
                      <th className="py-3.5 px-4">Candidate</th>
                      <th className="py-3.5 px-4">Course / Program</th>
                      <th className="py-3.5 px-4">Priority</th>
                      <th className="py-3.5 px-4">Stage</th>
                      <th className="py-3.5 px-4">Counsellor</th>
                      <th className="py-3.5 px-4">Next Follow-up</th>
                      <th className="py-3.5 px-4 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/70">
                    {leads.map((lead) => {
                      const priorityInfo = priorityBadgeStyles[lead.priority] || priorityBadgeStyles.medium
                      const isConverted = lead.status === 'converted' || lead.status === 'enrolled'

                      return (
                        <tr key={lead.id} className="hover:bg-slate-900/50 transition">
                          {/* Candidate Info */}
                          <td className="py-3.5 px-4">
                            <div className="flex items-center gap-3">
                              <div className="w-8 h-8 rounded-xl bg-purple-950 border border-purple-800 flex items-center justify-center font-bold text-xs text-purple-300 uppercase shrink-0">
                                {lead.name.charAt(0)}
                              </div>
                              <div>
                                <div className="flex items-center gap-2">
                                  <span className="font-bold text-white leading-tight">{lead.name}</span>
                                  {lead.city && (
                                    <span className="text-[10px] text-slate-400 font-mono">({lead.city})</span>
                                  )}
                                </div>
                                <p className="text-[11px] text-slate-400 mt-0.5">{lead.phone} • {lead.email}</p>
                              </div>
                            </div>
                          </td>

                          {/* Course & Source */}
                          <td className="py-3.5 px-4">
                            <p className="font-semibold text-slate-200 leading-tight">
                              {lead.course?.title || lead.course_title || 'General Technical Track'}
                            </p>
                            <span className="inline-block mt-0.5 text-[10px] font-mono px-1.5 py-0.2 rounded bg-slate-900 text-slate-400 border border-slate-800 uppercase">
                              {lead.source}
                            </span>
                          </td>

                          {/* Priority */}
                          <td className="py-3.5 px-4">
                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${priorityInfo.bg} flex items-center gap-1 w-fit`}>
                              <span>{priorityInfo.icon}</span>
                              <span>{priorityInfo.label}</span>
                            </span>
                          </td>

                          {/* Status */}
                          <td className="py-3.5 px-4">
                            <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${statusBadgeStyles[lead.status] || 'bg-slate-800 text-slate-300'}`}>
                              {lead.status.replace(/_/g, ' ')}
                            </span>
                          </td>

                          {/* Counsellor */}
                          <td className="py-3.5 px-4 text-slate-300 font-semibold">
                            {lead.assignedCounsellor?.name || lead.assigned_agent || 'Unassigned'}
                          </td>

                          {/* Next Follow-up */}
                          <td className="py-3.5 px-4">
                            {lead.next_follow_up_date ? (
                              <div>
                                <span className="font-mono text-slate-200">
                                  {new Date(lead.next_follow_up_date).toLocaleDateString()}
                                </span>
                                {lead.next_follow_up_time && (
                                  <span className="text-[10px] text-slate-400 block">{lead.next_follow_up_time}</span>
                                )}
                              </div>
                            ) : (
                              <span className="text-slate-500">—</span>
                            )}
                          </td>

                          {/* Actions */}
                          <td className="py-3.5 px-4 text-right">
                            <div className="flex items-center justify-end gap-1.5">
                              {/* Open Timeline Drawer */}
                              <button
                                type="button"
                                onClick={() => {
                                  setSelectedLead(lead)
                                  fetchLeadDetail(lead.id)
                                  setDrawerAction('timeline')
                                  setShowDrawer(true)
                                }}
                                className="px-2.5 py-1 rounded-lg text-xs font-bold text-purple-300 bg-purple-950/80 hover:bg-purple-900 border border-purple-800 transition flex items-center gap-1"
                                title="View Lead Profile & Activity Timeline"
                              >
                                <span>👁️</span>
                                <span>Timeline</span>
                              </button>

                              {/* Convert to LMS (if not already converted) */}
                              {!isConverted && (
                                <button
                                  type="button"
                                  onClick={() => openConversionModal(lead)}
                                  className="px-2.5 py-1 rounded-lg text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 shadow-sm transition flex items-center gap-1"
                                  title="Convert Lead to Enrolled LMS Student"
                                >
                                  <span>🎓</span>
                                  <span>Convert</span>
                                </button>
                              )}

                              {/* Edit Modal */}
                              <button
                                type="button"
                                onClick={() => openEditLeadModal(lead)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white bg-slate-900 hover:bg-slate-800 border border-slate-800 transition text-xs"
                                title="Edit Lead"
                              >
                                ✏️
                              </button>

                              {/* Delete */}
                              <button
                                type="button"
                                onClick={() => handleDeleteLead(lead)}
                                className="p-1 rounded-lg text-rose-400 hover:text-white bg-rose-950/40 hover:bg-rose-900 border border-rose-900 transition text-xs"
                                title="Delete Lead"
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
            </div>
          )}
        </div>
      )}

      {/* 5. TAB B: FOLLOW-UPS DESK */}
      {activeTab === 'follow_ups_desk' && (
        <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 shadow-xl space-y-5">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-800">
            <div>
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>⏰</span> Follow-ups Operational Desk
              </h3>
              <p className="text-xs text-slate-400 mt-0.5">
                Manage upcoming candidate calls, feedback reminders, and overdue follow-up commitments.
              </p>
            </div>

            {/* Sub Tabs */}
            <div className="flex items-center gap-2 flex-wrap">
              {(['overdue', 'today', 'upcoming', 'completed', 'all'] as const).map((tab) => (
                <button
                  key={tab}
                  type="button"
                  onClick={() => setFollowUpDeskTab(tab)}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold transition uppercase ${
                    followUpDeskTab === tab
                      ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                      : 'bg-slate-900 text-slate-400 hover:text-white hover:bg-slate-850'
                  }`}
                >
                  {tab}
                </button>
              ))}
            </div>
          </div>

          {/* Follow-up Items Table */}
          <div className="overflow-x-auto rounded-2xl border border-slate-800">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-900/90 text-slate-400 font-extrabold uppercase tracking-wider border-b border-slate-800">
                <tr>
                  <th className="py-3.5 px-4">Scheduled For</th>
                  <th className="py-3.5 px-4">Candidate</th>
                  <th className="py-3.5 px-4">Task & Notes</th>
                  <th className="py-3.5 px-4">Assigned Agent</th>
                  <th className="py-3.5 px-4">Status</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/70">
                {followUpsList.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500 font-semibold">
                      No follow-ups found in this category.
                    </td>
                  </tr>
                ) : (
                  followUpsList.map((item) => {
                    const isPending = item.status === 'pending'
                    const isOverdue = isPending && new Date(item.scheduled_at) < new Date()

                    return (
                      <tr key={item.id} className="hover:bg-slate-900/50 transition">
                        <td className="py-3.5 px-4 font-mono">
                          <span className={isOverdue ? 'text-rose-400 font-bold' : 'text-slate-200'}>
                            {new Date(item.scheduled_at).toLocaleString()}
                          </span>
                          {isOverdue && (
                            <span className="text-[10px] text-rose-400 font-bold block">⚠️ OVERDUE</span>
                          )}
                        </td>

                        <td className="py-3.5 px-4">
                          <p className="font-bold text-white">{item.enquiry?.name || `Lead #${item.enquiry_id}`}</p>
                          <p className="text-[11px] text-slate-400">{item.enquiry?.phone}</p>
                        </td>

                        <td className="py-3.5 px-4 max-w-sm">
                          <p className="font-semibold text-slate-200">{item.title}</p>
                          <p className="text-[11px] text-slate-400 mt-0.5 truncate">{item.notes || item.outcome || '—'}</p>
                        </td>

                        <td className="py-3.5 px-4 text-purple-300 font-semibold">
                          {item.assignedTo?.name || 'Unassigned'}
                        </td>

                        <td className="py-3.5 px-4">
                          <span
                            className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase ${
                              item.status === 'completed'
                                ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                                : isOverdue
                                ? 'bg-rose-950 text-rose-400 border border-rose-800'
                                : 'bg-blue-950 text-blue-400 border border-blue-800'
                            }`}
                          >
                            {item.status}
                          </span>
                        </td>

                        <td className="py-3.5 px-4 text-right">
                          <div className="flex items-center justify-end gap-2">
                            {isPending && (
                              <button
                                type="button"
                                onClick={() => {
                                  setCompletingFollowUp(item)
                                  setFollowUpOutcomeText('')
                                }}
                                className="px-2.5 py-1 rounded-lg text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 transition shadow-xs flex items-center gap-1"
                              >
                                <span>✓</span>
                                <span>Complete</span>
                              </button>
                            )}

                            {item.enquiry && (
                              <button
                                type="button"
                                onClick={() => {
                                  fetchLeadDetail(item.enquiry_id)
                                  setShowDrawer(true)
                                }}
                                className="p-1 rounded-lg text-purple-300 hover:text-white bg-purple-950/60 hover:bg-purple-900 border border-purple-800 text-xs"
                                title="Open Lead Timeline"
                              >
                                👁️
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    )
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 6. SLIDE-OVER LEAD DETAIL & RICH ACTIVITY TIMELINE DRAWER */}
      {showDrawer && selectedLead && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex justify-end">
          <div className="bg-slate-900 border-l border-slate-800 w-full max-w-2xl h-full shadow-2xl flex flex-col justify-between animate-in slide-in-from-right duration-300">
            {/* Drawer Header */}
            <div className="p-6 border-b border-slate-800 bg-slate-950 flex items-start justify-between">
              <div className="flex items-start gap-3">
                <div className="w-12 h-12 rounded-2xl bg-purple-600 flex items-center justify-center text-white font-black text-xl shadow-lg shadow-purple-600/30 shrink-0">
                  {selectedLead.name.charAt(0)}
                </div>
                <div>
                  <div className="flex items-center gap-2 flex-wrap">
                    <h3 className="font-black text-lg text-white leading-tight">{selectedLead.name}</h3>
                    <span className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${statusBadgeStyles[selectedLead.status]}`}>
                      {selectedLead.status.replace(/_/g, ' ')}
                    </span>
                  </div>
                  <p className="text-xs text-slate-400 mt-1 flex items-center gap-2 flex-wrap">
                    <span>📞 {selectedLead.phone}</span>
                    <span>•</span>
                    <span>✉️ {selectedLead.email}</span>
                    {selectedLead.city && <span>• 📍 {selectedLead.city}</span>}
                  </p>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setShowDrawer(false)}
                className="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition"
              >
                ✕
              </button>
            </div>

            {/* Drawer Body */}
            <div className="p-6 overflow-y-auto flex-grow space-y-6">
              {/* Lead Snapshot Cards */}
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs bg-slate-950/60 p-4 rounded-2xl border border-slate-800">
                <div>
                  <span className="text-[10px] font-bold uppercase text-slate-500 block">Course Track</span>
                  <strong className="text-white font-semibold">{selectedLead.course?.title || selectedLead.course_title || 'General'}</strong>
                </div>
                <div>
                  <span className="text-[10px] font-bold uppercase text-slate-500 block">Lead Source</span>
                  <span className="text-purple-300 font-bold uppercase">{selectedLead.source}</span>
                </div>
                <div>
                  <span className="text-[10px] font-bold uppercase text-slate-500 block">Assigned Counsellor</span>
                  <span className="text-slate-200 font-semibold">{selectedLead.assignedCounsellor?.name || selectedLead.assigned_agent || 'Unassigned'}</span>
                  {!selectedLead.assigned_counsellor_id && user && (
                    <button
                      type="button"
                      onClick={handleClaimLead}
                      disabled={saving}
                      className="ml-2 px-2 py-0.5 rounded-lg text-[10px] font-bold bg-purple-600 hover:bg-purple-500 text-white transition disabled:opacity-50"
                    >
                      Claim for me
                    </button>
                  )}
                </div>
                <div>
                  <span className="text-[10px] font-bold uppercase text-slate-500 block">Paid Amount</span>
                  <span className="text-emerald-400 font-bold">${Number(selectedLead.amount_paid || 0).toFixed(2)}</span>
                </div>
                <div>
                  <span className="text-[10px] font-bold uppercase text-slate-500 block">Payment Status</span>
                  <span className="text-amber-300 font-bold uppercase">{selectedLead.payment_status || 'unpaid'}</span>
                </div>
                <div>
                  <span className="text-[10px] font-bold uppercase text-slate-500 block">Next Follow-up</span>
                  <span className="text-slate-300 font-mono">{selectedLead.next_follow_up_date ? new Date(selectedLead.next_follow_up_date).toLocaleDateString() : 'None'}</span>
                </div>
              </div>

              {/* Action Tabs in Drawer */}
              <div className="flex items-center gap-1.5 border-b border-slate-800 pb-2 flex-wrap">
                <button
                  type="button"
                  onClick={() => setDrawerAction('timeline')}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold transition ${
                    drawerAction === 'timeline' ? 'bg-purple-600 text-white' : 'bg-slate-950 text-slate-400 hover:text-white'
                  }`}
                >
                  📜 Activity Stream
                </button>
                <button
                  type="button"
                  onClick={() => setDrawerAction('call')}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold transition ${
                    drawerAction === 'call' ? 'bg-blue-600 text-white' : 'bg-slate-950 text-slate-400 hover:text-white'
                  }`}
                >
                  📞 Log Call
                </button>
                <button
                  type="button"
                  onClick={() => setDrawerAction('note')}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold transition ${
                    drawerAction === 'note' ? 'bg-amber-600 text-white' : 'bg-slate-950 text-slate-400 hover:text-white'
                  }`}
                >
                  📝 Add Note
                </button>
                <button
                  type="button"
                  onClick={() => setDrawerAction('follow_up')}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold transition ${
                    drawerAction === 'follow_up' ? 'bg-orange-600 text-white' : 'bg-slate-950 text-slate-400 hover:text-white'
                  }`}
                >
                  ⏰ Schedule Follow-up
                </button>
                <button
                  type="button"
                  onClick={() => setDrawerAction('status')}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold transition ${
                    drawerAction === 'status' ? 'bg-indigo-600 text-white' : 'bg-slate-950 text-slate-400 hover:text-white'
                  }`}
                >
                  ⚡ Change Stage
                </button>
              </div>

              {/* ACTION FORM: LOG CALL */}
              {drawerAction === 'call' && (
                <form onSubmit={handleLogCall} className="bg-slate-950 p-4 rounded-2xl border border-blue-900/50 space-y-3">
                  <h4 className="text-xs font-extrabold uppercase tracking-wider text-blue-400 flex items-center gap-1.5">
                    <span>📞</span> Log Outbound / Inbound Call
                  </h4>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Call Duration</label>
                      <input
                        type="text"
                        value={callDuration}
                        onChange={(e) => setCallDuration(e.target.value)}
                        placeholder="e.g. 8 mins"
                        className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                      />
                    </div>
                    <div>
                      <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Call Outcome</label>
                      <select
                        value={callOutcome}
                        onChange={(e) => setCallOutcome(e.target.value)}
                        className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                      >
                        <option value="interested">Interested in Admission</option>
                        <option value="demo_requested">Requested Live Demo</option>
                        <option value="call_back_later">Call Back Later</option>
                        <option value="no_answer">No Answer / Busy</option>
                        <option value="not_interested">Not Interested</option>
                      </select>
                    </div>
                  </div>
                  <div>
                    <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Call Remarks / Summary</label>
                    <textarea
                      rows={2}
                      value={callNotes}
                      onChange={(e) => setCallNotes(e.target.value)}
                      placeholder="Candidate inquired about weekend AI batches..."
                      className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                    />
                  </div>
                  <button
                    type="submit"
                    disabled={saving}
                    className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-500 transition"
                  >
                    Save Call Log
                  </button>
                </form>
              )}

              {/* ACTION FORM: ADD NOTE */}
              {drawerAction === 'note' && (
                <form onSubmit={handleAddNote} className="bg-slate-950 p-4 rounded-2xl border border-amber-900/50 space-y-3">
                  <h4 className="text-xs font-extrabold uppercase tracking-wider text-amber-400 flex items-center gap-1.5">
                    <span>📝</span> Add Counsellor Note
                  </h4>
                  <textarea
                    rows={3}
                    value={generalNote}
                    onChange={(e) => setGeneralNote(e.target.value)}
                    required
                    placeholder="Type note regarding candidate profile or counselor discussion..."
                    className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                  />
                  <button
                    type="submit"
                    disabled={saving || !generalNote.trim()}
                    className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-amber-600 hover:bg-amber-500 transition"
                  >
                    Save Note
                  </button>
                </form>
              )}

              {/* ACTION FORM: SCHEDULE FOLLOW-UP */}
              {drawerAction === 'follow_up' && (
                <form onSubmit={handleScheduleFollowUp} className="bg-slate-950 p-4 rounded-2xl border border-orange-900/50 space-y-3">
                  <h4 className="text-xs font-extrabold uppercase tracking-wider text-orange-400 flex items-center gap-1.5">
                    <span>⏰</span> Schedule Next Follow-up
                  </h4>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Date & Time *</label>
                      <input
                        type="datetime-local"
                        value={scheduleFollowUpDate}
                        onChange={(e) => setScheduleFollowUpDate(e.target.value)}
                        required
                        className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                      />
                    </div>
                    <div>
                      <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Task Title</label>
                      <input
                        type="text"
                        value={scheduleFollowUpTitle}
                        onChange={(e) => setScheduleFollowUpTitle(e.target.value)}
                        placeholder="e.g. Call to discuss fees structure"
                        className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Instructions / Objectives</label>
                    <textarea
                      rows={2}
                      value={scheduleFollowUpNotes}
                      onChange={(e) => setScheduleFollowUpNotes(e.target.value)}
                      placeholder="Check if candidate had a chance to review syllabus PDF..."
                      className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                    />
                  </div>
                  <button
                    type="submit"
                    disabled={saving || !scheduleFollowUpDate}
                    className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-orange-600 hover:bg-orange-500 transition"
                  >
                    Confirm Follow-up Task
                  </button>
                </form>
              )}

              {/* ACTION FORM: CHANGE STAGE & PRIORITY */}
              {drawerAction === 'status' && (
                <form onSubmit={handleUpdateStatusAndPriority} className="bg-slate-950 p-4 rounded-2xl border border-indigo-900/50 space-y-3">
                  <h4 className="text-xs font-extrabold uppercase tracking-wider text-indigo-400 flex items-center gap-1.5">
                    <span>⚡</span> Update Stage & Priority
                  </h4>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Pipeline Stage</label>
                      <select
                        value={newStatusValue}
                        onChange={(e) => setNewStatusValue(e.target.value)}
                        className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                      >
                        <option value="new">New</option>
                        <option value="contacted">Contacted</option>
                        <option value="interested">Interested</option>
                        <option value="demo_scheduled">Demo Scheduled</option>
                        <option value="demo_completed">Demo Completed</option>
                        <option value="follow_up">Follow-up Needed</option>
                        <option value="payment_pending">Payment Pending</option>
                        <option value="admission_confirmed">Admission Confirmed</option>
                        <option value="lost">Lost / Dropped</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-[10px] font-bold uppercase text-slate-400 mb-1">Lead Priority</label>
                      <select
                        value={newPriorityValue}
                        onChange={(e) => setNewPriorityValue(e.target.value)}
                        className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                      >
                        <option value="hot">🔥 Hot Priority</option>
                        <option value="warm">⚡ Warm Priority</option>
                        <option value="cold">❄️ Cold Priority</option>
                      </select>
                    </div>
                  </div>
                  <button
                    type="submit"
                    disabled={saving}
                    className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-500 transition"
                  >
                    Apply Status Changes
                  </button>
                </form>
              )}

              {/* TIMELINE ACTIVITY FEED */}
              <div className="space-y-4">
                <h4 className="font-extrabold text-sm text-white flex items-center gap-2">
                  <span>📜</span> Chronological Activity Timeline ({selectedLead.activities?.length || 0})
                </h4>

                {loadingLeadDetail ? (
                  <div className="py-8 text-center text-slate-500 text-xs">Loading activity feed...</div>
                ) : !selectedLead.activities || selectedLead.activities.length === 0 ? (
                  <p className="text-xs text-slate-500 py-4 text-center">No timeline activity logged yet.</p>
                ) : (
                  <div className="relative border-l-2 border-slate-800 ml-3.5 space-y-4">
                    {selectedLead.activities.map((act) => {
                      let icon = '📝'
                      let color = 'bg-purple-950 text-purple-300 border-purple-800'
                      if (act.activity_type === 'call') {
                        icon = '📞'
                        color = 'bg-blue-950 text-blue-300 border-blue-800'
                      } else if (act.activity_type === 'follow_up') {
                        icon = '⏰'
                        color = 'bg-orange-950 text-orange-300 border-orange-800'
                      } else if (act.activity_type === 'payment_event') {
                        icon = '💳'
                        color = 'bg-yellow-950 text-yellow-300 border-yellow-800'
                      } else if (act.activity_type === 'conversion') {
                        icon = '🎓'
                        color = 'bg-emerald-950 text-emerald-300 border-emerald-800'
                      } else if (act.activity_type === 'status_change') {
                        icon = '⚡'
                        color = 'bg-indigo-950 text-indigo-300 border-indigo-800'
                      }

                      return (
                        <div key={act.id} className="relative pl-6">
                          <span className={`absolute -left-3.5 top-0 w-7 h-7 rounded-full flex items-center justify-center text-xs border ${color}`}>
                            {icon}
                          </span>
                          <div className="bg-slate-950/80 p-3 rounded-2xl border border-slate-800/80 space-y-1">
                            <div className="flex items-center justify-between text-xs">
                              <strong className="text-white font-bold">{act.title}</strong>
                              <span className="text-[10px] text-slate-500 font-mono">
                                {new Date(act.created_at).toLocaleString()}
                              </span>
                            </div>
                            {act.description && (
                              <p className="text-[11px] text-slate-300 whitespace-pre-wrap">{act.description}</p>
                            )}
                            <p className="text-[10px] text-slate-500">
                              By {act.user?.name || 'System'}
                            </p>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            </div>

            {/* Drawer Footer */}
            <div className="p-4 border-t border-slate-800 bg-slate-950 flex items-center justify-between">
              <button
                type="button"
                onClick={() => openConversionModal(selectedLead)}
                className="px-4 py-2 rounded-xl text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 transition flex items-center gap-1.5 shadow-md shadow-emerald-600/30"
              >
                <span>🎓</span>
                <span>Convert to LMS Student</span>
              </button>

              <button
                type="button"
                onClick={() => setShowDrawer(false)}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-900 transition"
              >
                Close Drawer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 7. MODAL: CRM → LMS CONVERSION ENGINE */}
      {showConvertModal && convertLead && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-emerald-800/80 rounded-3xl p-6 max-w-xl w-full shadow-2xl space-y-5 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>🎓</span> Convert Lead to LMS Student
              </h3>
              <button
                type="button"
                onClick={() => setShowConvertModal(false)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleConvertLeadSubmit} className="space-y-4">
              {/* Candidate Info Box */}
              <div className="bg-slate-950 p-3.5 rounded-2xl border border-slate-800 text-xs space-y-1">
                <p className="text-slate-400">Candidate: <strong className="text-white">{convertLead.name}</strong></p>
                <p className="text-slate-400">Email: <strong className="text-purple-300">{convertLead.email}</strong> • Phone: <strong className="text-slate-300">{convertLead.phone}</strong></p>
              </div>

              {/* Course Selection */}
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Select Enrolled Course / Track *
                </label>
                <select
                  value={convertCourseId}
                  onChange={(e) => {
                    setConvertCourseId(e.target.value)
                    setConvertBatchId('')
                  }}
                  required
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-emerald-500 transition"
                >
                  <option value="">-- Choose Course --</option>
                  {courses.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.title} {c.category ? `(${c.category})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              {/* Batch Cohort Assignment */}
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Assign Cohort Batch (RIT(CODE)BCDDMMYY)
                </label>
                <select
                  value={convertBatchId}
                  onChange={(e) => setConvertBatchId(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-emerald-500 transition"
                >
                  <option value="">-- Assign Cohort Batch (Optional) --</option>
                  {availableBatchesForConversion.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.code} — {b.name} (Starts: {new Date(b.start_date).toLocaleDateString()})
                    </option>
                  ))}
                </select>
              </div>

              {/* Payment Details */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Amount Paid ($)
                  </label>
                  <input
                    type="number"
                    value={convertAmountPaid}
                    onChange={(e) => setConvertAmountPaid(e.target.value)}
                    placeholder="0.00"
                    min={0}
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Payment Mode
                  </label>
                  <select
                    value={convertPaymentMode}
                    onChange={(e) => setConvertPaymentMode(e.target.value)}
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="upi">UPI / QR</option>
                    <option value="netbanking">Net Banking</option>
                    <option value="card">Credit / Debit Card</option>
                    <option value="cash">Cash Payment</option>
                    <option value="bank_transfer">Wire / NEFT</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Transaction ID
                  </label>
                  <input
                    type="text"
                    value={convertTransactionId}
                    onChange={(e) => setConvertTransactionId(e.target.value)}
                    placeholder="Txn reference"
                    className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowConvertModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !convertCourseId}
                  className="px-6 py-2 rounded-xl text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 transition flex items-center gap-2 shadow-lg shadow-emerald-600/30"
                >
                  {saving ? 'Activating LMS Access...' : 'Confirm LMS Conversion'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 8. MODAL: CREATE / EDIT LEAD */}
      {showLeadModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-2xl w-full shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-lg text-white flex items-center gap-2">
                <span>🎯</span> {editingLead ? `Edit Lead (${editingLead.name})` : 'Add New Candidate Lead'}
              </h3>
              <button
                type="button"
                onClick={() => setShowLeadModal(false)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSaveLead} className="space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Candidate Name *</label>
                  <input
                    type="text"
                    value={formName}
                    onChange={(e) => setFormName(e.target.value)}
                    required
                    placeholder="Full name"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Email Address *</label>
                  <input
                    type="email"
                    value={formEmail}
                    onChange={(e) => setFormEmail(e.target.value)}
                    required
                    placeholder="email@domain.com"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Phone / Mobile *</label>
                  <input
                    type="text"
                    value={formPhone}
                    onChange={(e) => setFormPhone(e.target.value)}
                    required
                    placeholder="+91 9876543210"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Interested Course</label>
                  <select
                    value={formCourseId}
                    onChange={(e) => setFormCourseId(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="">-- Select Course --</option>
                    {courses.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.title}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Lead Source</label>
                  <select
                    value={formSource}
                    onChange={(e) => setFormSource(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="website">Website Form</option>
                    <option value="google_ads">Google Ads</option>
                    <option value="social_media">Social Media</option>
                    <option value="referral">Student Referral</option>
                    <option value="direct_call">Direct Call / Inbound</option>
                    <option value="event">Webinar / Workshop</option>
                    <option value="walk_in">Walk-in Candidate</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Priority</label>
                  <select
                    value={formPriority}
                    onChange={(e) => setFormPriority(e.target.value as LeadPriority)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="hot">🔥 Hot Priority</option>
                    <option value="warm">⚡ Warm Priority</option>
                    <option value="cold">❄️ Cold Priority</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Assigned Counsellor</label>
                  <select
                    value={formCounsellorId}
                    onChange={(e) => setFormCounsellorId(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  >
                    <option value="">-- Unassigned --</option>
                    {counsellors.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} ({c.role})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">City / Location</label>
                  <input
                    type="text"
                    value={formCity}
                    onChange={(e) => setFormCity(e.target.value)}
                    placeholder="e.g. Bangalore, Remote"
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">First Follow-up Date</label>
                  <input
                    type="date"
                    value={formNextFollowUpDate}
                    onChange={(e) => setFormNextFollowUpDate(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">Candidate Inquiry Message / Notes</label>
                <textarea
                  rows={2}
                  value={formMessage}
                  onChange={(e) => setFormMessage(e.target.value)}
                  placeholder="Candidate notes or initial questions..."
                  className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowLeadModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-6 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-lg shadow-purple-600/30"
                >
                  {saving ? 'Saving...' : editingLead ? 'Save Changes' : 'Create Lead'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 9. MODAL: COMPLETE FOLLOW-UP WITH OUTCOME */}
      {completingFollowUp && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-emerald-900/70 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="font-black text-base text-white flex items-center gap-2">
                <span>✓</span> Complete Follow-up Task
              </h3>
              <button
                type="button"
                onClick={() => setCompletingFollowUp(null)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleCompleteFollowUpSubmit} className="space-y-3">
              <p className="text-xs text-slate-300">
                Candidate: <strong className="text-white">{completingFollowUp.enquiry?.name}</strong>
              </p>
              <p className="text-[11px] text-slate-400">
                Task: {completingFollowUp.title}
              </p>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Call Outcome / Discussion Summary *
                </label>
                <textarea
                  rows={3}
                  value={followUpOutcomeText}
                  onChange={(e) => setFollowUpOutcomeText(e.target.value)}
                  required
                  placeholder="e.g. Candidate confirmed enrollment and will pay registration fee tomorrow..."
                  className="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-emerald-500 transition"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setCompletingFollowUp(null)}
                  className="px-3.5 py-1.5 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !followUpOutcomeText.trim()}
                  className="px-5 py-1.5 rounded-xl text-xs font-extrabold text-white bg-emerald-600 hover:bg-emerald-500 transition shadow-xs"
                >
                  {saving ? 'Saving...' : 'Mark Completed'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
