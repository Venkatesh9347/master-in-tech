import { useState, useEffect, useCallback } from 'react'
import API from '../../../services/api'
import type { AdminMockInterviewerItem } from '../../../types/mockInterview'

export default function InterviewersTab() {
  const [interviewers, setInterviewers] = useState<AdminMockInterviewerItem[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')

  // Create / Edit Modal
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingInterviewer, setEditingInterviewer] = useState<AdminMockInterviewerItem | null>(null)

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [designation, setDesignation] = useState('')
  const [company, setCompany] = useState('')
  const [experience, setExperience] = useState<number>(5)
  const [skillsInput, setSkillsInput] = useState('')
  const [bio, setBio] = useState('')
  const [internalNotes, setInternalNotes] = useState('')
  const [isActive, setIsActive] = useState(true)

  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<{ text: string; type: 'success' | 'error' } | null>(null)

  const showMsg = (text: string, type: 'success' | 'error' = 'success') => {
    setMsg({ text, type })
    setTimeout(() => setMsg(null), 4500)
  }

  const fetchInterviewers = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      if (search.trim()) params.append('search', search.trim())
      if (statusFilter !== 'all') params.append('status', statusFilter)

      const res = await API.get<{ data?: AdminMockInterviewerItem[] } | AdminMockInterviewerItem[]>(
        `/admin/mock-interviews/interviewers?${params.toString()}`
      )
      const list = Array.isArray(res.data) ? res.data : res.data?.data || []
      setInterviewers(list)
    } catch {
      showMsg('Failed to load interviewers.', 'error')
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter])

  useEffect(() => {
    fetchInterviewers()
  }, [fetchInterviewers])

  const openCreateModal = () => {
    setEditingInterviewer(null)
    setName('')
    setEmail('')
    setPhone('')
    setDesignation('')
    setCompany('')
    setExperience(5)
    setSkillsInput('')
    setBio('')
    setInternalNotes('')
    setIsActive(true)
    setIsModalOpen(true)
  }

  const openEditModal = (item: AdminMockInterviewerItem) => {
    setEditingInterviewer(item)
    setName(item.name)
    setEmail(item.email)
    setPhone(item.phone || '')
    setDesignation(item.designation)
    setCompany(item.company)
    setExperience(item.years_of_experience)
    setSkillsInput((item.skills || []).join(', '))
    setBio(item.bio || '')
    setInternalNotes(item.internal_notes || '')
    setIsActive(item.is_active)
    setIsModalOpen(true)
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)

    const skillsArray = skillsInput
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)

    const payload = {
      name: name.trim(),
      email: email.trim(),
      phone: phone.trim() || null,
      designation: designation.trim(),
      company: company.trim(),
      years_of_experience: Number(experience),
      skills: skillsArray.length > 0 ? skillsArray : ['General Engineering'],
      bio: bio.trim() || null,
      internal_notes: internalNotes.trim() || null,
      is_active: isActive,
    }

    try {
      if (editingInterviewer) {
        await API.put(`/admin/mock-interviews/interviewers/${editingInterviewer.id}`, payload)
        showMsg(`Interviewer '${payload.name}' updated successfully!`)
      } else {
        await API.post('/admin/mock-interviews/interviewers', payload)
        showMsg(`Interviewer '${payload.name}' created successfully!`)
      }
      setIsModalOpen(false)
      fetchInterviewers()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to save interviewer.', 'error')
    } finally {
      setSaving(false)
    }
  }

  const handleToggleDeactivate = async (item: AdminMockInterviewerItem) => {
    try {
      await API.delete(`/admin/mock-interviews/interviewers/${item.id}`)
      showMsg(`Interviewer '${item.name}' status updated.`)
      fetchInterviewers()
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      showMsg(resp.response?.data?.message || 'Failed to update interviewer status.', 'error')
    }
  }

  return (
    <div className="space-y-6">
      {msg && (
        <div
          className={`px-5 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-lg ${
            msg.type === 'success'
              ? 'bg-emerald-950/80 border border-emerald-800 text-emerald-200'
              : 'bg-rose-950/80 border border-rose-800 text-rose-200'
          }`}
        >
          <span>{msg.text}</span>
          <button type="button" onClick={() => setMsg(null)} className="opacity-80 hover:opacity-100">
            ✕
          </button>
        </div>
      )}

      {/* Header with Search & Add Button */}
      <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-950 p-4 rounded-2xl border border-slate-800">
        <div className="flex items-center gap-3 w-full sm:w-auto">
          <div className="w-full sm:w-72 relative">
            <span className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs">🔍</span>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search interviewers by name, company, skill..."
              className="w-full bg-slate-900 border border-slate-800 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
            />
          </div>

          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="bg-slate-900 border border-slate-800 text-slate-300 text-xs font-semibold rounded-xl px-3 py-2 focus:outline-none focus:border-purple-500"
          >
            <option value="all">All Status</option>
            <option value="active">Active Only</option>
            <option value="inactive">Inactive Only</option>
          </select>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="w-full sm:w-auto px-4 py-2.5 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center justify-center gap-2"
        >
          <span>➕</span>
          <span>Add Professional Interviewer</span>
        </button>
      </div>

      {/* Interviewers Grid */}
      {loading ? (
        <div className="py-20 text-center text-slate-400 space-y-3">
          <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin mx-auto" />
          <p className="text-xs font-semibold">Loading interviewers directory...</p>
        </div>
      ) : interviewers.length === 0 ? (
        <div className="py-20 text-center text-slate-400 space-y-2 bg-slate-950 border border-slate-800 rounded-3xl p-8">
          <span className="text-3xl block">👨‍💼</span>
          <p className="text-sm font-bold text-white">No Professional Interviewers Found</p>
          <p className="text-xs text-slate-500">Create interviewer profiles to start defining interview availability slots.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {interviewers.map((item) => (
            <div
              key={item.id}
              className={`bg-slate-950 border rounded-3xl p-5 space-y-4 shadow-lg transition hover:border-slate-750 flex flex-col justify-between ${
                item.is_active ? 'border-slate-800' : 'border-slate-850 opacity-70'
              }`}
            >
              <div className="space-y-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h3 className="font-bold text-white text-base leading-snug">{item.name}</h3>
                    <p className="text-xs text-purple-400 font-semibold">{item.designation}</p>
                    <p className="text-xs text-slate-400">{item.company} • {item.years_of_experience} yrs exp</p>
                  </div>
                  <span
                    className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${
                      item.is_active
                        ? 'bg-emerald-950/80 text-emerald-300 border-emerald-700'
                        : 'bg-slate-900 text-slate-500 border-slate-700'
                    }`}
                  >
                    {item.is_active ? 'Active' : 'Inactive'}
                  </span>
                </div>

                {item.bio && (
                  <p className="text-xs text-slate-400 line-clamp-2 leading-relaxed">{item.bio}</p>
                )}

                {/* Skills tags */}
                {item.skills && item.skills.length > 0 && (
                  <div className="flex flex-wrap gap-1">
                    {item.skills.map((s, idx) => (
                      <span
                        key={idx}
                        className="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-900 text-purple-300 border border-slate-800"
                      >
                        {s}
                      </span>
                    ))}
                  </div>
                )}

                {/* Internal notes banner */}
                {item.internal_notes && (
                  <div className="bg-slate-900/80 border border-slate-800/80 p-2.5 rounded-xl text-[11px] text-slate-400 space-y-0.5">
                    <span className="font-bold text-slate-300 text-[10px] uppercase tracking-wider block">Admin Note:</span>
                    <p className="line-clamp-2">{item.internal_notes}</p>
                  </div>
                )}
              </div>

              <div className="pt-3 border-t border-slate-850 flex items-center justify-between gap-2">
                <div className="text-[11px] text-slate-400 font-medium space-x-2">
                  <span>📅 {item.slots_count ?? 0} slots</span>
                  <span>•</span>
                  <span>🎙️ {item.interviews_count ?? 0} mock sessions</span>
                </div>

                <div className="flex items-center gap-1.5">
                  <button
                    type="button"
                    onClick={() => openEditModal(item)}
                    className="px-2.5 py-1 rounded-lg text-xs font-bold text-slate-300 hover:text-white bg-slate-900 hover:bg-slate-800 border border-slate-800 transition"
                  >
                    Edit
                  </button>

                  <button
                    type="button"
                    onClick={() => handleToggleDeactivate(item)}
                    className={`px-2.5 py-1 rounded-lg text-xs font-bold transition border ${
                      item.is_active
                        ? 'text-rose-400 hover:text-rose-300 bg-rose-950/40 border-rose-900/60'
                        : 'text-emerald-400 hover:text-emerald-300 bg-emerald-950/40 border-emerald-900/60'
                    }`}
                  >
                    {item.is_active ? 'Deactivate' : 'Activate'}
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create / Edit Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm overflow-y-auto">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl space-y-5 my-8">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h3 className="text-lg font-black text-white flex items-center gap-2">
                <span>{editingInterviewer ? '✏️' : '➕'}</span>
                <span>{editingInterviewer ? 'Edit Professional Interviewer' : 'Add Professional Interviewer'}</span>
              </h3>
              <button
                type="button"
                onClick={() => setIsModalOpen(false)}
                className="text-slate-400 hover:text-white text-sm"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Interviewer Name <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Anand Mahindra"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Email Address <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="e.g. anand@company.com"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Company / Organization <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={company}
                    onChange={(e) => setCompany(e.target.value)}
                    placeholder="e.g. Google / Microsoft"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Designation <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={designation}
                    onChange={(e) => setDesignation(e.target.value)}
                    placeholder="e.g. Staff Architect"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Years of Exp <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="number"
                    step="0.5"
                    min="0"
                    max="60"
                    required
                    value={experience}
                    onChange={(e) => setExperience(Number(e.target.value))}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Phone (Private to Admin)
                  </label>
                  <input
                    type="text"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    placeholder="e.g. +91 9876543210"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-300 block mb-1">
                    Skills / Expertise (Comma separated) <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={skillsInput}
                    onChange={(e) => setSkillsInput(e.target.value)}
                    placeholder="e.g. React, Node.js, Kubernetes, AWS"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                  />
                </div>
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Public Bio (Visible to Student)</label>
                <textarea
                  rows={2}
                  value={bio}
                  onChange={(e) => setBio(e.target.value)}
                  placeholder="Short professional summary..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div>
                <label className="text-xs font-bold text-slate-300 block mb-1">Internal Administrative Notes</label>
                <textarea
                  rows={2}
                  value={internalNotes}
                  onChange={(e) => setInternalNotes(e.target.value)}
                  placeholder="Private admin feedback, availability preferences, hourly rates..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                />
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="isActive"
                  checked={isActive}
                  onChange={(e) => setIsActive(e.target.checked)}
                  className="w-4 h-4 rounded border-slate-800 bg-slate-950 text-purple-600 focus:ring-purple-500"
                />
                <label htmlFor="isActive" className="text-xs font-semibold text-slate-300 cursor-pointer">
                  Active Interviewer (Available for creating new interview slots)
                </label>
              </div>

              <div className="flex justify-end gap-2.5 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  disabled={saving}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:text-white bg-slate-800 transition"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 shadow-md shadow-purple-600/30 transition flex items-center gap-1.5"
                >
                  {saving ? 'Saving...' : editingInterviewer ? '✓ Update Interviewer' : '✓ Create Interviewer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
