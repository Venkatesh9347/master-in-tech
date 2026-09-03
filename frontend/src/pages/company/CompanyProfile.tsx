import { useEffect, useState } from 'react'
import API from '../../services/api'

interface CompanyProfileData {
  id: number
  name: string
  logo?: string | null
  website?: string | null
  industry?: string | null
  company_size?: string | null
  description?: string | null
  location?: string | null
  hr_name: string
  email: string
  phone: string
  hiring_technologies?: string[] | null
  hiring_requirements?: string | null
  status: string
  approved_at?: string | null
}

export default function CompanyProfile() {
  const [profile, setProfile] = useState<CompanyProfileData | null>(null)
  const [loading, setLoading] = useState(true)

  const [name, setName] = useState('')
  const [logo, setLogo] = useState('')
  const [website, setWebsite] = useState('')
  const [industry, setIndustry] = useState('')
  const [companySize, setCompanySize] = useState('')
  const [location, setLocation] = useState('')
  const [hrName, setHrName] = useState('')
  const [phone, setPhone] = useState('')
  const [description, setDescription] = useState('')
  const [hiringRequirements, setHiringRequirements] = useState('')
  const [techInput, setTechInput] = useState('')
  const [techList, setTechList] = useState<string[]>([])

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const fetchProfile = async () => {
    setLoading(true)
    try {
      const res = await API.get<CompanyProfileData>('/company/profile')
      const p = res.data
      setProfile(p)
      setName(p.name || '')
      setLogo(p.logo || '')
      setWebsite(p.website || '')
      setIndustry(p.industry || '')
      setCompanySize(p.company_size || '')
      setLocation(p.location || '')
      setHrName(p.hr_name || '')
      setPhone(p.phone || '')
      setDescription(p.description || '')
      setHiringRequirements(p.hiring_requirements || '')
      setTechList(Array.isArray(p.hiring_technologies) ? p.hiring_technologies : [])
    } catch {
      setErrorMsg('Failed to load company profile.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchProfile()
  }, [])

  const handleAddTech = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && techInput.trim()) {
      e.preventDefault()
      if (!techList.includes(techInput.trim())) {
        setTechList([...techList, techInput.trim()])
      }
      setTechInput('')
    }
  }

  const handleRemoveTech = (tech: string) => {
    setTechList(techList.filter((t) => t !== tech))
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    try {
      await API.put('/company/profile', {
        name: name.trim(),
        logo: logo.trim() || null,
        website: website.trim() || null,
        industry: industry.trim(),
        company_size: companySize,
        location: location.trim(),
        hr_name: hrName.trim(),
        phone: phone.trim(),
        description: description.trim() || null,
        hiring_requirements: hiringRequirements.trim() || null,
        hiring_technologies: techList,
      })

      setSuccessMsg('✓ Company profile updated successfully!')
      fetchProfile()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string } } }
      setErrorMsg(resp.response?.data?.message || 'Failed to update company profile.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="py-20 text-center text-slate-500 text-xs flex flex-col items-center gap-3">
        <div className="w-8 h-8 border-2 border-purple-500/20 border-t-purple-500 rounded-full animate-spin" />
        <span>Loading company profile...</span>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      {/* Header */}
      <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-950/80 border border-emerald-800 text-emerald-300 text-xs font-bold uppercase tracking-wider">
            <span>✓</span> Verified Corporate Partner Account
          </div>
          <h1 className="text-2xl font-black text-white">{profile?.name}</h1>
          <p className="text-xs text-slate-400">
            Account Status: <strong className="text-emerald-400 uppercase font-mono">{profile?.status}</strong> • Partner ID: <span className="font-mono text-slate-300">#{profile?.id}</span>
          </p>
        </div>

        <div className="text-xs text-slate-400 bg-slate-900 border border-slate-800 p-3 rounded-2xl">
          <p className="font-semibold text-slate-200">Registered Email (Login User ID):</p>
          <p className="font-mono text-purple-300 mt-0.5">{profile?.email}</p>
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

      {/* Profile Form */}
      <div className="bg-slate-950/80 backdrop-blur border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="space-y-4">
            <h2 className="text-xs font-extrabold uppercase tracking-wider text-purple-400 border-b border-slate-800 pb-2">
              Corporate Enterprise Information
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Company Name *
                </label>
                <input
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Website URL
                </label>
                <input
                  type="url"
                  value={website}
                  onChange={(e) => setWebsite(e.target.value)}
                  placeholder="https://..."
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Industry
                </label>
                <input
                  type="text"
                  value={industry}
                  onChange={(e) => setIndustry(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Company Size
                </label>
                <input
                  type="text"
                  value={companySize}
                  onChange={(e) => setCompanySize(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Headquarters Location *
                </label>
                <input
                  type="text"
                  value={location}
                  onChange={(e) => setLocation(e.target.value)}
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                About Company & Work Culture
              </label>
              <textarea
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="Describe your organization, mission, team dynamics..."
                className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
              />
            </div>
          </div>

          <div className="space-y-4">
            <h2 className="text-xs font-extrabold uppercase tracking-wider text-purple-400 border-b border-slate-800 pb-2">
              HR & Talent Acquisition Contact
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  HR / Lead Recruiter Name *
                </label>
                <input
                  type="text"
                  value={hrName}
                  onChange={(e) => setHrName(e.target.value)}
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                  Contact Phone Number *
                </label>
                <input
                  type="text"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
                />
              </div>
            </div>
          </div>

          <div className="space-y-4">
            <h2 className="text-xs font-extrabold uppercase tracking-wider text-purple-400 border-b border-slate-800 pb-2">
              Target Technology Stacks & Hiring Tags
            </h2>

            <div>
              <label className="block text-xs font-bold text-slate-400 mb-2 uppercase tracking-wider">
                Technology Tags:
              </label>
              <div className="flex flex-wrap gap-2 mb-3">
                {techList.map((t) => (
                  <span
                    key={t}
                    className="px-3 py-1 rounded-xl text-xs font-bold bg-purple-950 text-purple-300 border border-purple-800 flex items-center gap-1.5"
                  >
                    <span>{t}</span>
                    <button
                      type="button"
                      onClick={() => handleRemoveTech(t)}
                      className="text-purple-400 hover:text-white"
                    >
                      ✕
                    </button>
                  </span>
                ))}
              </div>
              <input
                type="text"
                value={techInput}
                onChange={(e) => setTechInput(e.target.value)}
                onKeyDown={handleAddTech}
                placeholder="Type tech tag (e.g. AWS Bedrock) and press Enter..."
                className="w-full sm:w-1/2 px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white"
              />
            </div>
          </div>

          <div className="flex justify-end pt-4 border-t border-slate-800">
            <button
              type="submit"
              disabled={saving}
              className="px-8 py-3 rounded-2xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 transition shadow-lg shadow-purple-600/30"
            >
              {saving ? 'Saving Profile...' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
