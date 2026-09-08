import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import ImageUploadField from '../../components/admin/ImageUploadField'

interface InstructorItem {
  id: number
  name: string
  designation?: string | null
  company?: string | null
  bio?: string | null
  avatar?: string | null
  rating?: string | null
  graduates_count?: string | null
  experience_years?: string | null
  skills?: string[] | null
  display_order: number
  is_active: boolean
}

export default function AdminInstructors() {
  const [instructors, setInstructors] = useState<InstructorItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editingInst, setEditingInst] = useState<InstructorItem | null>(null)

  // Form State
  const [name, setName] = useState('')
  const [designation, setDesignation] = useState('')
  const [company, setCompany] = useState('')
  const [bio, setBio] = useState('')
  const [avatar, setAvatar] = useState('👨‍🏫')
  const [rating, setRating] = useState('')
  const [graduates, setGraduates] = useState('')
  const [experience, setExperience] = useState('')
  const [skillsStr, setSkillsStr] = useState('')
  const [displayOrder, setDisplayOrder] = useState(0)
  const [isActive, setIsActive] = useState(true)

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadInstructors = useCallback(() => {
    setLoading(true)
    API.get<InstructorItem[]>('/admin/instructors')
      .then((res) => setInstructors(Array.isArray(res.data) ? res.data : []))
      .catch(() => setErrorMsg('Failed to load instructors.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadInstructors()
  }, [loadInstructors])

  const openCreateModal = () => {
    setEditingInst(null)
    setName('')
    setDesignation('')
    setCompany('')
    setBio('')
    setAvatar('👨‍🏫')
    setRating('')
    setGraduates('')
    setExperience('')
    setSkillsStr('')
    setDisplayOrder(instructors.length + 1)
    setIsActive(true)
    setShowModal(true)
  }

  const openEditModal = (inst: InstructorItem) => {
    setEditingInst(inst)
    setName(inst.name)
    setDesignation(inst.designation || '')
    setCompany(inst.company || '')
    setBio(inst.bio || '')
    setAvatar(inst.avatar || '👨‍🏫')
    setRating(inst.rating || '')
    setGraduates(inst.graduates_count || '')
    setExperience(inst.experience_years || '')
    setSkillsStr(Array.isArray(inst.skills) ? inst.skills.join(', ') : '')
    setDisplayOrder(inst.display_order)
    setIsActive(inst.is_active)
    setShowModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const skills = skillsStr
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)

    const payload = {
      name: name.trim(),
      designation: designation.trim() || undefined,
      company: company.trim() || undefined,
      bio: bio.trim() || undefined,
      avatar: avatar.trim() || undefined,
      rating: rating.trim() || undefined,
      graduates_count: graduates.trim() || undefined,
      experience_years: experience.trim() || undefined,
      skills,
      display_order: Number(displayOrder),
      is_active: isActive,
    }

    try {
      if (editingInst) {
        await API.put(`/admin/instructors/${editingInst.id}`, payload)
        setSuccessMsg(`Faculty member '${name}' updated!`)
      } else {
        await API.post('/admin/instructors', payload)
        setSuccessMsg(`Faculty member '${name}' created!`)
      }
      setShowModal(false)
      loadInstructors()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to save instructor.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (inst: InstructorItem) => {
    if (!window.confirm(`Delete faculty profile for '${inst.name}'?`)) return

    try {
      await API.delete(`/admin/instructors/${inst.id}`)
      setSuccessMsg(`Faculty member '${inst.name}' removed.`)
      loadInstructors()
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to delete instructor.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Faculty & Instructor Management</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Administer public faculty profiles, company affiliations, and mentor credentials.
          </p>
        </div>

        <button
          type="button"
          onClick={openCreateModal}
          className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition flex items-center gap-2"
        >
          <span>+</span> Add Faculty Member
        </button>
      </div>

      {successMsg && (
        <div className="p-4 bg-emerald-950/80 border border-emerald-800 text-emerald-300 rounded-2xl text-xs font-bold">
          ✓ {successMsg}
        </div>
      )}

      {errorMsg && (
        <div className="p-4 bg-red-950/80 border border-red-800 text-red-300 rounded-2xl text-xs font-bold">
          ⚠️ {errorMsg}
        </div>
      )}

      {/* Grid of Instructors */}
      <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8">
        {loading ? (
          <p className="text-xs text-slate-500 py-8 text-center">Loading faculty list...</p>
        ) : instructors.length === 0 ? (
          <div className="py-10 text-center text-slate-500 text-xs">
            <span className="text-3xl mb-2 block">👨‍🏫</span>
            <p className="font-bold text-slate-300">No faculty members found</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {instructors.map((inst) => (
              <div
                key={inst.id}
                className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4 hover:border-slate-700 transition"
              >
                <div className="flex items-center gap-3">
                  <div className="w-12 h-12 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-center text-2xl">
                    {inst.avatar || '👨‍🏫'}
                  </div>
                  <div>
                    <h3 className="font-bold text-white text-sm leading-tight">{inst.name}</h3>
                    <p className="text-[11px] text-purple-400 font-semibold">{inst.designation}</p>
                    {inst.company && (
                      <p className="text-[10px] text-slate-400 font-medium">{inst.company}</p>
                    )}
                  </div>
                </div>

                <p className="text-xs text-slate-300 line-clamp-3 leading-relaxed">
                  {inst.bio || 'No biography provided.'}
                </p>

                <div className="flex items-center justify-between text-[11px] text-slate-400 pt-2 border-t border-slate-800/80">
                  <span>⭐ {inst.rating}</span>
                  <span>🎓 {inst.graduates_count}</span>
                </div>

                <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => openEditModal(inst)}
                    className="px-3 py-1 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold transition"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    onClick={() => handleDelete(inst)}
                    className="px-3 py-1 rounded-lg bg-red-950/60 text-red-300 hover:bg-red-900 text-xs font-bold transition"
                  >
                    Delete
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* MODAL */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-800 space-y-5 text-white max-h-[90vh] overflow-y-auto">
            <div>
              <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-purple-950 text-purple-300 border border-purple-800 rounded-md">
                Faculty Editor
              </span>
              <h3 className="text-lg font-bold text-white mt-1">
                {editingInst ? `Edit ${editingInst.name}` : 'Add Faculty Member'}
              </h3>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Full Name <span className="text-red-400">*</span>
                  </label>
                  <input
                    type="text"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Dr. Sarah Johnson"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                    required
                  />
                </div>
              </div>

              {/* Faculty Photo / Avatar */}
              <div>
                <ImageUploadField
                  label="Faculty Profile Photo / Avatar"
                  value={avatar}
                  onChange={(url) => setAvatar(url)}
                  folder="instructors"
                  aspectRatio="avatar"
                  helpText="Upload a professional mentor headshot (JPG, PNG, WebP) or enter emoji/URL."
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Professional Designation
                  </label>
                  <input
                    type="text"
                    value={designation}
                    onChange={(e) => setDesignation(e.target.value)}
                    placeholder="e.g. Senior Lead Architect"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                    Company / Organization
                  </label>
                  <input
                    type="text"
                    value={company}
                    onChange={(e) => setCompany(e.target.value)}
                    placeholder="e.g. Microsoft / Google"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Faculty Bio
                </label>
                <textarea
                  value={bio}
                  onChange={(e) => setBio(e.target.value)}
                  rows={3}
                  placeholder="Industry background, architecture specialties, teaching experience..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Rating</label>
                  <input
                    type="text"
                    value={rating}
                    onChange={(e) => setRating(e.target.value)}
                    placeholder="4.9 ★"
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Graduates</label>
                  <input
                    type="text"
                    value={graduates}
                    onChange={(e) => setGraduates(e.target.value)}
                    placeholder="1,400+ students"
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Experience</label>
                  <input
                    type="text"
                    value={experience}
                    onChange={(e) => setExperience(e.target.value)}
                    placeholder="10+ Years"
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Skills & Domains (comma separated)
                </label>
                <input
                  type="text"
                  value={skillsStr}
                  onChange={(e) => setSkillsStr(e.target.value)}
                  placeholder="Full Stack, Cloud, Kubernetes, AI"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-md transition disabled:opacity-50"
                >
                  {saving ? 'Saving...' : 'Save Profile'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
