import { useEffect, useState } from 'react'
import { useAuth } from '../../context/useAuth'
import API from '../../services/api'

interface TutorProfileData {
  id: number
  name: string
  email: string
  role: string
  phone?: string
  headline?: string
  expertise?: string
  bio?: string
}

export default function TutorProfile() {
  const { user } = useAuth()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [headline, setHeadline] = useState('')
  const [expertise, setExpertise] = useState('')
  const [bio, setBio] = useState('')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  useEffect(() => {
    API.get<TutorProfileData>('/tutor/profile')
      .then((res) => {
        const u = res.data
        setName(u.name || '')
        setEmail(u.email || '')
        setPhone(u.phone || '')
        setHeadline(u.headline || 'Senior Technical Educator & Curriculum Architect')
        setExpertise(u.expertise || 'Software Engineering, Full Stack, Cloud, DevOps')
        setBio(
          u.bio ||
            'Passionate engineering instructor with extensive industry experience building production microservices and mentoring learners.'
        )
      })
      .catch(() => {
        setName(user?.name || '')
        setEmail(user?.email || '')
        setHeadline('Senior Technical Educator')
        setExpertise('Web Development, Cloud Computing')
        setBio('Instructor at Master In Tech.')
      })
      .finally(() => setLoading(false))
  }, [user])

  const handleProfileSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (password && password !== confirmPassword) {
      setErrorMsg('New password and confirmation do not match.')
      return
    }

    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      await API.put('/tutor/profile', {
        name,
        phone: phone || undefined,
        headline: headline || undefined,
        expertise: expertise || undefined,
        bio: bio || undefined,
        password: password || undefined,
      })

      setSuccessMsg('Faculty profile and teaching credentials updated successfully!')
      setPassword('')
      setConfirmPassword('')
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setErrorMsg(response.response?.data?.message || 'Failed to update profile.')
    } finally {
      setSaving(false)
    }
  }

  const skillBadges = expertise
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)

  if (loading) {
    return (
      <div className="p-12 text-center text-xs font-semibold text-slate-500">
        <span className="animate-spin inline-block w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full mb-2" />
        <p>Loading faculty profile...</p>
      </div>
    )
  }

  return (
    <div className="space-y-8">
      {/* Profile Banner */}
      <section className="bg-white rounded-3xl p-8 border border-slate-200/80 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
        <div className="flex items-center gap-5">
          <div className="w-20 h-20 rounded-3xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white font-black text-3xl flex items-center justify-center shadow-lg shadow-blue-500/20 shrink-0">
            {name ? name.charAt(0).toUpperCase() : 'T'}
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-black text-slate-900">{name || 'Instructor Name'}</h1>
              <span className="bg-blue-50 text-blue-700 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full border border-blue-200">
                Verified Faculty
              </span>
            </div>
            <p className="text-xs text-slate-600 font-semibold mt-0.5">{headline}</p>
            <p className="text-xs text-slate-400 mt-0.5">{email}</p>
          </div>
        </div>
      </section>

      {/* Edit Form + Faculty Info */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* Left 2 Cols: Form */}
        <div className="lg:col-span-2 bg-white rounded-3xl p-8 border border-slate-200/80 shadow-xs space-y-6">
          <div>
            <h2 className="text-lg font-bold text-slate-900">Edit Faculty Profile</h2>
            <p className="text-xs text-slate-500 mt-0.5">
              Update your public instructor credentials, contact details, and teaching portfolio.
            </p>
          </div>

          {successMsg && (
            <div className="p-4 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-2xl border border-emerald-200">
              ✓ {successMsg}
            </div>
          )}
          {errorMsg && (
            <div className="p-4 bg-red-50 text-red-700 text-xs font-bold rounded-2xl border border-red-200">
              ⚠️ {errorMsg}
            </div>
          )}

          <form onSubmit={handleProfileSave} className="space-y-5 text-xs">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Full Name
                </label>
                <input
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  required
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Phone Number
                </label>
                <input
                  type="text"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="+91 98765 43210"
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                />
              </div>
            </div>

            <div>
              <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                Faculty Headline / Professional Title
              </label>
              <input
                type="text"
                value={headline}
                onChange={(e) => setHeadline(e.target.value)}
                placeholder="e.g. Lead Full Stack Architect & Tech Educator"
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                required
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                Teaching Bio & Professional Background
              </label>
              <textarea
                value={bio}
                onChange={(e) => setBio(e.target.value)}
                rows={4}
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none leading-relaxed"
                required
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                Areas of Expertise & Technical Skills (comma separated)
              </label>
              <input
                type="text"
                value={expertise}
                onChange={(e) => setExpertise(e.target.value)}
                placeholder="e.g. React, Next.js, Node.js, Laravel, Cloud"
                className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
              />
            </div>

            <div className="pt-4 border-t border-slate-100 space-y-4">
              <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-400">
                Update Password (Optional)
              </h3>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-700 text-[10px] mb-1">New Password</label>
                  <input
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="Leave blank to keep current"
                    className="w-full px-4 py-2 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                    minLength={6}
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-700 text-[10px] mb-1">
                    Confirm New Password
                  </label>
                  <input
                    type="password"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    placeholder="Confirm new password"
                    className="w-full px-4 py-2 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                    minLength={6}
                  />
                </div>
              </div>
            </div>

            <div className="pt-2 flex justify-end">
              <button
                type="submit"
                disabled={saving}
                className="px-6 py-2.5 rounded-xl font-bold text-xs text-white bg-blue-600 hover:bg-blue-700 active:bg-blue-800 shadow-sm transition disabled:opacity-50"
              >
                {saving ? 'Saving Profile...' : 'Save Profile Changes'}
              </button>
            </div>
          </form>
        </div>

        {/* Right Col: Faculty Card Preview */}
        <div className="lg:col-span-1 space-y-6">
          <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs space-y-4">
            <h3 className="text-sm font-bold text-slate-900">Faculty Preview Card</h3>

            <div className="p-4 bg-slate-50 rounded-2xl border border-slate-200/80 space-y-3">
              <div className="flex items-center gap-3">
                <div className="w-12 h-12 rounded-xl bg-blue-600 text-white font-black text-lg flex items-center justify-center shadow-xs">
                  {name ? name.charAt(0).toUpperCase() : 'T'}
                </div>
                <div>
                  <h4 className="text-sm font-bold text-slate-900">{name || 'Instructor Name'}</h4>
                  <p className="text-[11px] text-blue-600 font-semibold">{headline}</p>
                </div>
              </div>

              <p className="text-xs text-slate-600 leading-relaxed line-clamp-4">{bio}</p>

              <div>
                <p className="text-[10px] font-bold text-slate-400 uppercase mb-1.5">Expertise</p>
                <div className="flex flex-wrap gap-1.5">
                  {skillBadges.map((badge, idx) => (
                    <span
                      key={idx}
                      className="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 text-[10px] font-bold border border-blue-200"
                    >
                      {badge}
                    </span>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
