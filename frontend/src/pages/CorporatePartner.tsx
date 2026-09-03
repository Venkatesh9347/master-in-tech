import { useState } from 'react'
import { Link } from 'react-router-dom'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'

const popularTechStacks = [
  'Full Stack Java',
  'Python & Django',
  'AI & Machine Learning',
  'React & Node.js',
  'DevOps & Kubernetes',
  'Cloud Architecture (AWS/GCP)',
  'Data Engineering & Snowflake',
  'Cybersecurity',
  'Golang Microservices',
]

export default function CorporatePartner() {
  const [companyName, setCompanyName] = useState('')
  const [website, setWebsite] = useState('')
  const [industry, setIndustry] = useState('Information Technology & Services')
  const [companySize, setCompanySize] = useState('51-200 employees')
  const [location, setLocation] = useState('')
  const [hrName, setHrName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [selectedTechs, setSelectedTechs] = useState<string[]>([])
  const [customTech, setCustomTech] = useState('')
  const [hiringRequirements, setHiringRequirements] = useState('')
  const [message, setMessage] = useState('')

  const [submitting, setSubmitting] = useState(false)
  const [successData, setSuccessData] = useState<{ message: string; companyName: string } | null>(null)
  const [errorMsg, setErrorMsg] = useState('')

  const toggleTech = (tech: string) => {
    if (selectedTechs.includes(tech)) {
      setSelectedTechs(selectedTechs.filter((t) => t !== tech))
    } else {
      setSelectedTechs([...selectedTechs, tech])
    }
  }

  const addCustomTech = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && customTech.trim()) {
      e.preventDefault()
      if (!selectedTechs.includes(customTech.trim())) {
        setSelectedTechs([...selectedTechs, customTech.trim()])
      }
      setCustomTech('')
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSubmitting(true)
    setErrorMsg('')

    const payload = {
      name: companyName.trim(),
      website: website.trim() || undefined,
      industry: industry.trim(),
      company_size: companySize,
      location: location.trim(),
      hr_name: hrName.trim(),
      email: email.trim().toLowerCase(),
      phone: phone.trim(),
      hiring_technologies: selectedTechs.length > 0 ? selectedTechs : undefined,
      hiring_requirements: hiringRequirements.trim() || undefined,
      message: message.trim() || undefined,
    }

    try {
      const res = await API.post<{ message: string }>('/corporate-partner/register', payload)
      setSuccessData({
        message: res.data.message || 'Partnership request submitted successfully!',
        companyName: payload.name,
      })
    } catch (err: unknown) {
      const resp = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const msg = resp.response?.data?.message || 'Failed to submit partnership request. Please verify your details.'
      setErrorMsg(msg)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 flex flex-col selection:bg-purple-600 selection:text-white">
      <Navbar />

      {/* Hero Banner */}
      <section className="relative overflow-hidden bg-slate-950 border-b border-slate-800/80 pt-14 pb-16">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_80%_at_50%_-20%,rgba(147,51,234,0.15),rgba(255,255,255,0))]" />

        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center space-y-4">
          <div className="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-purple-950/80 border border-purple-800 text-purple-300 text-xs font-bold uppercase tracking-wider">
            <span>🤝</span> MasterInTech Corporate & Placement Network
          </div>
          <h1 className="text-3xl sm:text-5xl lg:text-6xl font-black text-white tracking-tight leading-tight">
            Partner With <span className="text-transparent bg-clip-text bg-gradient-to-r from-purple-400 via-indigo-300 to-cyan-400">MasterInTech</span>
          </h1>
          <p className="text-slate-400 text-sm sm:text-base max-w-2xl mx-auto leading-relaxed">
            Hire pre-vetted, job-ready technology professionals trained across AI, Cloud Architecture, Full Stack Engineering, and Enterprise DevOps.
          </p>

          <div className="flex justify-center gap-4 pt-2">
            <Link
              to="/login"
              className="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-850 border border-slate-800 text-xs font-bold text-slate-300 hover:text-white transition inline-flex items-center gap-1.5"
            >
              <span>🔑</span>
              <span>Existing Corporate Partner Login</span>
            </Link>
          </div>
        </div>
      </section>

      {/* Main Registration Area */}
      <main className="flex-grow max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full">
        {successData ? (
          <div className="bg-slate-950 border border-purple-800/80 rounded-3xl p-10 text-center space-y-6 shadow-2xl animate-in zoom-in-95 duration-200">
            <div className="w-16 h-16 rounded-full bg-purple-950 border border-purple-700 text-3xl flex items-center justify-center mx-auto shadow-inner">
              🎉
            </div>
            <div className="space-y-2">
              <h2 className="text-2xl font-black text-white">Partnership Request Received!</h2>
              <p className="text-sm text-slate-300 font-medium max-w-md mx-auto">
                Thank you for partnering with MasterInTech. Our Corporate Placement Cell is reviewing the details for <strong className="text-purple-300">{successData.companyName}</strong>.
              </p>
            </div>

            <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 text-xs text-slate-400 text-left max-w-lg mx-auto space-y-2">
              <p className="font-bold text-slate-200 flex items-center gap-1.5">
                <span>🛡️</span> What happens next:
              </p>
              <ul className="list-disc list-inside space-y-1 text-slate-400">
                <li>Our Placement Officer will verify your corporate credentials within 24 business hours.</li>
                <li>Upon approval, recruiter login credentials for the <strong className="text-slate-200">Company Portal</strong> will be dispatched to your official email.</li>
                <li>You will be able to publish job openings directly to our student cohort placement portal.</li>
              </ul>
            </div>

            <div className="flex justify-center gap-4 pt-2">
              <Link
                to="/placements"
                className="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-xs font-black text-white shadow-lg shadow-purple-600/30 transition"
              >
                Browse Placement Portal
              </Link>
            </div>
          </div>
        ) : (
          <div className="bg-slate-950/90 backdrop-blur border border-slate-800 rounded-3xl p-6 sm:p-10 shadow-2xl space-y-8">
            <div>
              <h2 className="text-xl sm:text-2xl font-black text-white">Submit Corporate Partnership Request</h2>
              <p className="text-xs text-slate-400 mt-1">
                Fill in your company and recruiter details. We do not automatically grant portal access without verification.
              </p>
            </div>

            {errorMsg && (
              <div className="bg-rose-950/80 border border-rose-800 text-rose-200 px-5 py-3 rounded-2xl text-xs font-semibold">
                {errorMsg}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-6">
              {/* Section 1: Company Profile */}
              <div className="space-y-4">
                <h3 className="text-xs font-extrabold uppercase tracking-wider text-purple-400 border-b border-slate-800/80 pb-2">
                  1. Company Profile
                </h3>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Company / Enterprise Name *
                    </label>
                    <input
                      type="text"
                      value={companyName}
                      onChange={(e) => setCompanyName(e.target.value)}
                      required
                      placeholder="e.g. Microsoft Azure Ecosystem / Innovate Corp"
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Company Website URL
                    </label>
                    <input
                      type="url"
                      value={website}
                      onChange={(e) => setWebsite(e.target.value)}
                      placeholder="https://yourcompany.com"
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Industry Sector *
                    </label>
                    <select
                      value={industry}
                      onChange={(e) => setIndustry(e.target.value)}
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-purple-500 transition"
                    >
                      <option value="Information Technology & Services">IT & Software Services</option>
                      <option value="Artificial Intelligence & ML">AI & Deep Tech</option>
                      <option value="FinTech & Banking">FinTech & Banking</option>
                      <option value="Cloud & DevOps Infrastructure">Cloud & DevOps Infrastructure</option>
                      <option value="HealthTech">HealthTech</option>
                      <option value="E-Commerce & Retail">E-Commerce & Retail</option>
                      <option value="Cybersecurity">Cybersecurity</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Company Size
                    </label>
                    <select
                      value={companySize}
                      onChange={(e) => setCompanySize(e.target.value)}
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-purple-500 transition"
                    >
                      <option value="1-50 employees">1 - 50 employees</option>
                      <option value="51-200 employees">51 - 200 employees</option>
                      <option value="201-1000 employees">201 - 1,000 employees</option>
                      <option value="1000+ employees">1,000+ Enterprise</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Headquarters / Office Location *
                    </label>
                    <input
                      type="text"
                      value={location}
                      onChange={(e) => setLocation(e.target.value)}
                      required
                      placeholder="e.g. Hyderabad, Bangalore, Remote"
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>
                </div>
              </div>

              {/* Section 2: Recruiter / HR Representative */}
              <div className="space-y-4">
                <h3 className="text-xs font-extrabold uppercase tracking-wider text-purple-400 border-b border-slate-800/80 pb-2">
                  2. HR / Talent Acquisition Representative
                </h3>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Recruiter / HR Full Name *
                    </label>
                    <input
                      type="text"
                      value={hrName}
                      onChange={(e) => setHrName(e.target.value)}
                      required
                      placeholder="e.g. Sarah Jenkins"
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Official Work Email *
                    </label>
                    <input
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      required
                      placeholder="e.g. hr@company.com"
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                      Contact Phone *
                    </label>
                    <input
                      type="text"
                      value={phone}
                      onChange={(e) => setPhone(e.target.value)}
                      required
                      placeholder="e.g. +91 9876543210"
                      className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                    />
                  </div>
                </div>
              </div>

              {/* Section 3: Hiring Requirements */}
              <div className="space-y-4">
                <h3 className="text-xs font-extrabold uppercase tracking-wider text-purple-400 border-b border-slate-800/80 pb-2">
                  3. Technology Stacks & Hiring Requirements
                </h3>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-2 uppercase tracking-wider">
                    Select Technologies You Are Hiring For:
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {popularTechStacks.map((tech) => {
                      const isSelected = selectedTechs.includes(tech)
                      return (
                        <button
                          key={tech}
                          type="button"
                          onClick={() => toggleTech(tech)}
                          className={`px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
                            isSelected
                              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                              : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800'
                          }`}
                        >
                          <span>{isSelected ? '✓' : '+'}</span>
                          <span>{tech}</span>
                        </button>
                      )
                    })}
                  </div>

                  <div className="mt-3">
                    <input
                      type="text"
                      value={customTech}
                      onChange={(e) => setCustomTech(e.target.value)}
                      onKeyDown={addCustomTech}
                      placeholder="Type additional skill and press Enter..."
                      className="w-full sm:w-1/2 px-3.5 py-2 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Estimated Hiring Count & Requirement Details
                  </label>
                  <textarea
                    rows={2}
                    value={hiringRequirements}
                    onChange={(e) => setHiringRequirements(e.target.value)}
                    placeholder="e.g. Looking to hire 15 Freshers / Junior Engineers for Full Stack & AI roles with CTC 8-12 LPA."
                    className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wider">
                    Additional Message / Note to Placement Cell
                  </label>
                  <textarea
                    rows={2}
                    value={message}
                    onChange={(e) => setMessage(e.target.value)}
                    placeholder="Any specific requests or preferred drive schedules..."
                    className="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500 transition"
                  />
                </div>
              </div>

              <div className="flex items-center justify-between pt-4 border-t border-slate-800">
                <span className="text-[11px] text-slate-500">
                  🔒 Information is protected and used strictly for placement verification.
                </span>

                <button
                  type="submit"
                  disabled={submitting}
                  className="px-8 py-3 rounded-2xl text-xs font-extrabold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 transition shadow-lg shadow-purple-600/30 flex items-center gap-2"
                >
                  <span>{submitting ? 'Submitting Request...' : 'Submit Partnership Request'}</span>
                  <span>→</span>
                </button>
              </div>
            </form>
          </div>
        )}
      </main>

      <Footer />
    </div>
  )
}
