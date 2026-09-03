import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import ImageUploadField from '../../components/admin/ImageUploadField'

interface HomeSectionItem {
  id: number
  section_key: string
  title?: string | null
  subtitle?: string | null
  badge?: string | null
  content?: Record<string, unknown> | null
  is_enabled: boolean
  sort_order: number
}

const SECTION_LABELS: Record<string, { label: string; icon: string; description: string }> = {
  banner: { label: 'Top Announcement Banner', icon: '📢', description: 'Header promotion ribbon with CTA button' },
  hero: { label: 'Hero Header & Value Proposition', icon: '🚀', description: 'Primary headline, subtitle, CTAs, and live platform metrics' },
  explore_courses: { label: 'Explore Our Courses Section', icon: '📚', description: 'Interactive course grid with dynamic category filters' },
  learning_paths: { label: 'Learning Paths / Career Roadmaps', icon: '🗺️', description: 'Structured multi-course specialization tracks' },
  instructors: { label: 'Industry Faculty Mentors', icon: '👨‍🏫', description: 'Profiles of lead architects and research faculty' },
  testimonials: { label: 'Student Testimonials & Reviews', icon: '💬', description: 'Alumni career transition success stories' },
  events: { label: 'Live Masterclasses & Workshops', icon: '📅', description: 'Upcoming live webinars and weekend technical sessions' },
  faq: { label: 'Frequently Asked Questions', icon: '❓', description: 'Admissions, curriculum, and certificate FAQs' },
  cta: { label: 'Admissions CTA Banner', icon: '⚡', description: 'Bottom inquiry encouragement banner' },
}

export default function AdminHomeCMS() {
  const [sections, setSections] = useState<HomeSectionItem[]>([])
  const [loading, setLoading] = useState(true)
  const [editingSection, setEditingSection] = useState<HomeSectionItem | null>(null)

  // Edit Form State
  const [title, setTitle] = useState('')
  const [subtitle, setSubtitle] = useState('')
  const [badge, setBadge] = useState('')
  const [primaryBtnText, setPrimaryBtnText] = useState('')
  const [primaryBtnUrl, setPrimaryBtnUrl] = useState('')
  const [secondaryBtnText, setSecondaryBtnText] = useState('')
  const [imageUrl, setImageUrl] = useState('')

  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadSections = useCallback(() => {
    setLoading(true)
    API.get<HomeSectionItem[]>('/admin/home-sections')
      .then((res) => setSections(Array.isArray(res.data) ? res.data : []))
      .catch(() => setErrorMsg('Failed to load home page sections.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadSections()
  }, [loadSections])

  const openEditModal = (sec: HomeSectionItem) => {
    setEditingSection(sec)
    setTitle(sec.title || '')
    setSubtitle(sec.subtitle || '')
    setBadge(sec.badge || '')

    const content = (sec.content || {}) as Record<string, string>
    setPrimaryBtnText(content.primary_button_text || content.cta_text || content.button_text || '')
    setPrimaryBtnUrl(content.primary_button_url || '')
    setSecondaryBtnText(content.secondary_button_text || '')
    setImageUrl(content.image_url || content.background_image || '')
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!editingSection) return

    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const updatedContent = {
      ...(editingSection.content || {}),
      ...(primaryBtnText ? { primary_button_text: primaryBtnText, button_text: primaryBtnText, cta_text: primaryBtnText } : {}),
      ...(primaryBtnUrl ? { primary_button_url: primaryBtnUrl } : {}),
      ...(secondaryBtnText ? { secondary_button_text: secondaryBtnText } : {}),
      ...(imageUrl ? { image_url: imageUrl, background_image: imageUrl } : {}),
    }

    try {
      await API.put(`/admin/home-sections/${editingSection.id}`, {
        title: title.trim() || null,
        subtitle: subtitle.trim() || null,
        badge: badge.trim() || null,
        content: updatedContent,
      })

      setSuccessMsg(`Section '${editingSection.section_key}' updated successfully!`)
      setEditingSection(null)
      loadSections()
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to update section.')
    } finally {
      setSaving(false)
    }
  }

  const toggleSection = async (sec: HomeSectionItem) => {
    try {
      const res = await API.post<{ message: string; is_enabled: boolean }>(
        `/admin/home-sections/${sec.id}/toggle`
      )
      setSections((prev) =>
        prev.map((item) =>
          item.id === sec.id ? { ...item, is_enabled: res.data.is_enabled } : item
        )
      )
      setSuccessMsg(res.data.message)
      setTimeout(() => setSuccessMsg(''), 3000)
    } catch {
      setErrorMsg('Failed to toggle section visibility.')
    }
  }

  return (
    <div className="space-y-6">
      {/* Notifications */}
      {successMsg && (
        <div className="p-3 bg-emerald-950/80 border border-emerald-800 text-emerald-300 rounded-2xl text-xs font-bold">
          ✓ {successMsg}
        </div>
      )}
      {errorMsg && (
        <div className="p-3 bg-red-950/80 border border-red-800 text-red-300 rounded-2xl text-xs font-bold">
          ⚠ {errorMsg}
        </div>
      )}

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-black text-white">Home Page Layout & CMS Builder</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Enable/disable sections, edit hero copy, buttons, badges, banners, and marketing text.
          </p>
        </div>
      </div>

      {/* Sections List */}
      {loading ? (
        <div className="p-8 text-center text-xs font-bold text-slate-500">Loading CMS sections...</div>
      ) : (
        <div className="space-y-3">
          {sections.map((sec) => {
            const meta = SECTION_LABELS[sec.section_key] || {
              label: sec.section_key,
              icon: '📄',
              description: 'Custom section',
            }

            return (
              <div
                key={sec.id}
                className={`p-5 rounded-3xl border transition flex flex-col md:flex-row md:items-center justify-between gap-4 ${
                  sec.is_enabled
                    ? 'bg-slate-950 border-slate-800 shadow-sm'
                    : 'bg-slate-950/50 border-slate-900 opacity-60'
                }`}
              >
                <div className="flex items-start gap-4">
                  <span className="w-10 h-10 rounded-2xl bg-purple-950 text-purple-300 border border-purple-800 flex items-center justify-center text-lg shrink-0">
                    {meta.icon}
                  </span>
                  <div>
                    <div className="flex items-center gap-2">
                      <h3 className="font-bold text-sm text-white">{meta.label}</h3>
                      <span className="font-mono text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-400">
                        {sec.section_key}
                      </span>
                    </div>
                    <p className="text-xs text-slate-400 mt-0.5">{meta.description}</p>
                    {sec.title && (
                      <p className="text-xs text-purple-300 font-semibold mt-1 truncate max-w-lg">
                        Headline: "{sec.title}"
                      </p>
                    )}
                  </div>
                </div>

                <div className="flex items-center gap-3 self-end md:self-center">
                  <button
                    type="button"
                    onClick={() => toggleSection(sec)}
                    className={`px-3 py-1.5 rounded-xl text-xs font-bold border transition ${
                      sec.is_enabled
                        ? 'bg-emerald-950/80 text-emerald-300 border-emerald-700 hover:bg-emerald-900'
                        : 'bg-slate-900 text-slate-400 border-slate-700 hover:bg-slate-800'
                    }`}
                  >
                    {sec.is_enabled ? '● Enabled Live' : '○ Disabled'}
                  </button>

                  <button
                    type="button"
                    onClick={() => openEditModal(sec)}
                    className="px-3.5 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold shadow-xs transition"
                  >
                    ✏️ Edit Copy & Images
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Edit Section Modal */}
      {editingSection && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-xs overflow-y-auto">
          <div className="bg-slate-950 border border-slate-800 rounded-3xl p-6 sm:p-8 max-w-xl w-full text-white space-y-5 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div>
                <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-purple-950 text-purple-300 border border-purple-700">
                  Home Section CMS
                </span>
                <h3 className="text-base font-black text-white mt-1">
                  Edit Section: {SECTION_LABELS[editingSection.section_key]?.label || editingSection.section_key}
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setEditingSection(null)}
                className="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center font-bold"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSave} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Section Headline / Title
                </label>
                <input
                  type="text"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="e.g. Learn Technology From Fundamentals to Advanced"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Subtitle / Supporting Copy
                </label>
                <textarea
                  value={subtitle}
                  onChange={(e) => setSubtitle(e.target.value)}
                  rows={3}
                  placeholder="Enter supporting paragraph text..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">
                  Badge / Tag Label
                </label>
                <input
                  type="text"
                  value={badge}
                  onChange={(e) => setBadge(e.target.value)}
                  placeholder="e.g. Premier Tech Learning Platform"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white focus:ring-2 focus:ring-purple-500 outline-none"
                />
              </div>

              {/* Section Image / Background Management */}
              <div className="pt-2 border-t border-slate-800">
                <ImageUploadField
                  label="Section Image / Promotional Graphic"
                  value={imageUrl}
                  onChange={(url) => setImageUrl(url)}
                  folder="branding"
                  aspectRatio="banner"
                  helpText="Optional header banner or promotional graphic for this section."
                />
              </div>

              {editingSection.section_key === 'hero' && (
                <div className="p-4 bg-slate-950 rounded-2xl border border-slate-800 space-y-3">
                  <h4 className="font-bold text-white text-[11px] uppercase tracking-wider">Hero Button Configuration</h4>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-slate-400 text-[10px] mb-1">Primary CTA Button</label>
                      <input
                        type="text"
                        value={primaryBtnText}
                        onChange={(e) => setPrimaryBtnText(e.target.value)}
                        placeholder="Explore Courses"
                        className="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-white outline-none"
                      />
                    </div>
                    <div>
                      <label className="block text-slate-400 text-[10px] mb-1">Primary Button Link</label>
                      <input
                        type="text"
                        value={primaryBtnUrl}
                        onChange={(e) => setPrimaryBtnUrl(e.target.value)}
                        placeholder="/courses"
                        className="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-white outline-none"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-slate-400 text-[10px] mb-1">Secondary CTA Button</label>
                    <input
                      type="text"
                      value={secondaryBtnText}
                      onChange={(e) => setSecondaryBtnText(e.target.value)}
                      placeholder="Enquire Now"
                      className="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-white outline-none"
                    />
                  </div>
                </div>
              )}

              {editingSection.section_key === 'banner' && (
                <div className="p-4 bg-slate-950 rounded-2xl border border-slate-800 space-y-2">
                  <label className="block text-slate-400 text-[10px] mb-1">Banner Button Text</label>
                  <input
                    type="text"
                    value={primaryBtnText}
                    onChange={(e) => setPrimaryBtnText(e.target.value)}
                    placeholder="Enquire Now"
                    className="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-white outline-none"
                  />
                </div>
              )}

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setEditingSection(null)}
                  className="px-4 py-2 rounded-xl text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-md transition disabled:opacity-50"
                >
                  {saving ? 'Saving...' : 'Save Section Changes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
