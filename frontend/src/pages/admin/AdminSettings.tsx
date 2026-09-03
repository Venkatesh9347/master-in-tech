import { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'
import ImageUploadField from '../../components/admin/ImageUploadField'

interface SettingEntry {
  id: number
  key: string
  value: string | null
  group: string
}

export default function AdminSettings() {
  const [settings, setSettings] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const loadSettings = useCallback(() => {
    setLoading(true)
    API.get<SettingEntry[]>('/admin/settings')
      .then((res) => {
        const map: Record<string, string> = {}
        if (Array.isArray(res.data)) {
          res.data.forEach((s) => {
            map[s.key] = s.value || ''
          })
        }
        setSettings(map)
      })
      .catch(() => setErrorMsg('Failed to load website settings.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadSettings()
  }, [loadSettings])

  const handleChange = (key: string, value: string) => {
    setSettings((prev) => ({ ...prev, [key]: value }))
  }

  const handleSaveAll = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSuccessMsg('')

    const payload = Object.entries(settings).map(([key, value]) => ({
      key,
      value,
      group: key.startsWith('social_') ? 'social' : key.startsWith('seo_') ? 'seo' : 'general',
    }))

    try {
      await API.put('/admin/settings', { settings: payload })
      setSuccessMsg('Website configuration saved successfully!')
      setTimeout(() => setSuccessMsg(''), 4000)
    } catch {
      setErrorMsg('Failed to save settings.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-white">Global Website Settings</h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Configure platform branding, contact details, social links, and SEO metadata.
          </p>
        </div>

        <button
          type="button"
          onClick={handleSaveAll}
          disabled={saving}
          className="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-xs font-bold text-white shadow-md shadow-purple-600/30 transition disabled:opacity-50"
        >
          {saving ? 'Saving...' : '💾 Save All Settings'}
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

      {loading ? (
        <p className="text-xs text-slate-500 py-8 text-center">Loading settings...</p>
      ) : (
        <form onSubmit={handleSaveAll} className="space-y-6 text-xs">
          {/* General & Identity */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8 space-y-4">
            <h2 className="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
              <span>🏛️</span> Brand & Platform Identity
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Platform Name</label>
                <input
                  type="text"
                  value={settings.site_name || ''}
                  onChange={(e) => handleChange('site_name', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none focus:ring-2 focus:ring-purple-500"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Tagline</label>
                <input
                  type="text"
                  value={settings.site_tagline || ''}
                  onChange={(e) => handleChange('site_tagline', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none focus:ring-2 focus:ring-purple-500"
                />
              </div>
            </div>

            {/* Brand Media Assets */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-slate-900">
              <ImageUploadField
                label="Website Brand Logo"
                value={settings.site_logo}
                onChange={(url) => handleChange('site_logo', url)}
                folder="branding"
                aspectRatio="video"
                helpText="Main navbar and footer brand logo (SVG/PNG with transparent background)."
              />

              <ImageUploadField
                label="Browser Favicon"
                value={settings.site_favicon}
                onChange={(url) => handleChange('site_favicon', url)}
                folder="branding"
                aspectRatio="square"
                helpText="32x32 or 64x64 browser tab icon (PNG/ICO/SVG)."
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Graduates Badge Text</label>
                <input
                  type="text"
                  value={settings.graduates_badge_text || ''}
                  onChange={(e) => handleChange('graduates_badge_text', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Office / Tech Hub Location</label>
                <input
                  type="text"
                  value={settings.address || ''}
                  onChange={(e) => handleChange('address', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>
            </div>
          </div>

          {/* Contact Information */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8 space-y-4">
            <h2 className="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
              <span>📞</span> Admissions & Support Contact
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Admissions Email</label>
                <input
                  type="email"
                  value={settings.contact_email || ''}
                  onChange={(e) => handleChange('contact_email', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Admissions Phone Hotline</label>
                <input
                  type="text"
                  value={settings.contact_phone || ''}
                  onChange={(e) => handleChange('contact_phone', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Official WhatsApp Support</label>
                <input
                  type="text"
                  value={settings.whatsapp_number || ''}
                  onChange={(e) => handleChange('whatsapp_number', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>
            </div>
          </div>

          {/* SEO & Meta */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8 space-y-4">
            <h2 className="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
              <span>🔍</span> SEO & Search Optimization
            </h2>

            <div>
              <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Meta Title</label>
              <input
                type="text"
                value={settings.seo_title || ''}
                onChange={(e) => handleChange('seo_title', e.target.value)}
                className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Meta Description</label>
              <textarea
                value={settings.seo_description || ''}
                onChange={(e) => handleChange('seo_description', e.target.value)}
                rows={2}
                className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
              />
            </div>
          </div>

          {/* Social Channels */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8 space-y-4">
            <h2 className="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
              <span>🌐</span> Official Social Channels
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">LinkedIn Profile</label>
                <input
                  type="text"
                  value={settings.social_linkedin || ''}
                  onChange={(e) => handleChange('social_linkedin', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Twitter / X Profile</label>
                <input
                  type="text"
                  value={settings.social_twitter || ''}
                  onChange={(e) => handleChange('social_twitter', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">YouTube Channel</label>
                <input
                  type="text"
                  value={settings.social_youtube || ''}
                  onChange={(e) => handleChange('social_youtube', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">GitHub Organization</label>
                <input
                  type="text"
                  value={settings.social_github || ''}
                  onChange={(e) => handleChange('social_github', e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
                />
              </div>
            </div>
          </div>

          {/* Footer Copy */}
          <div className="bg-slate-950 rounded-3xl border border-slate-800 p-6 sm:p-8 space-y-4">
            <h2 className="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
              <span>📄</span> Footer Copy & Copyright
            </h2>

            <div>
              <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Footer About Text</label>
              <textarea
                value={settings.footer_text || ''}
                onChange={(e) => handleChange('footer_text', e.target.value)}
                rows={2}
                className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-400 uppercase text-[10px] mb-1">Copyright Notice</label>
              <input
                type="text"
                value={settings.copyright_text || ''}
                onChange={(e) => handleChange('copyright_text', e.target.value)}
                className="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white outline-none"
              />
            </div>
          </div>
        </form>
      )}
    </div>
  )
}
