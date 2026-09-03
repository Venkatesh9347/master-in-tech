import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import API from '../services/api'

interface NavLinkItem {
  label: string
  to?: string
  url?: string
}

const FALLBACK_QUICK_LINKS: NavLinkItem[] = [
  { label: 'Explore Courses', to: '/courses' },
  { label: 'Live Masterclasses', to: '/events' },
  { label: 'Expert Instructors', to: '/instructors' },
  { label: 'Free Resources', to: '/resources' },
  { label: 'Verify Certificate', to: '/verify-certificate' },
]

const FALLBACK_DOMAINS: NavLinkItem[] = [
  { label: 'Artificial Intelligence', to: '/courses' },
  { label: 'Machine Learning', to: '/courses' },
  { label: 'Data Science & Analytics', to: '/courses' },
  { label: 'Full Stack Development', to: '/courses' },
  { label: 'Cloud & DevOps', to: '/courses' },
]

const FALLBACK_SUPPORT: NavLinkItem[] = [
  { label: 'About Us', to: '/about' },
  { label: 'FAQs & Helpdesk', to: '/faq' },
  { label: 'Contact Support', to: '/contact' },
  { label: 'Admissions & Enquiry', to: '/contact' },
]

export default function Footer() {
  const [settings, setSettings] = useState<Record<string, string>>({})
  const [quickLinks, setQuickLinks] = useState<NavLinkItem[]>(FALLBACK_QUICK_LINKS)
  const [supportLinks, setSupportLinks] = useState<NavLinkItem[]>(FALLBACK_SUPPORT)

  useEffect(() => {
    API.get<Record<string, string>>('/public/settings')
      .then((res) => {
        if (res.data && typeof res.data === 'object') {
          setSettings(res.data)
        }
      })
      .catch(() => {})

    API.get<{ footer_learning?: { label: string; url: string }[]; footer_support?: { label: string; url: string }[] }>(
      '/public/navigation'
    )
      .then((res) => {
        if (Array.isArray(res.data.footer_learning) && res.data.footer_learning.length > 0) {
          setQuickLinks(res.data.footer_learning.map((i) => ({ label: i.label, to: i.url })))
        }
        if (Array.isArray(res.data.footer_support) && res.data.footer_support.length > 0) {
          setSupportLinks(res.data.footer_support.map((i) => ({ label: i.label, to: i.url })))
        }
      })
      .catch(() => {})
  }, [])

  const siteName = settings.site_name || 'MasterInTech'
  const footerText =
    settings.footer_text ||
    'Empowering engineers and career changers worldwide with hands-on, mentor-led programs in AI, Cloud, Full Stack, Data, and Enterprise Systems.'
  const copyrightText = settings.copyright_text || `© ${new Date().getFullYear()} ${siteName}. All rights reserved.`
  const badgeText = settings.graduates_badge_text || 'Over 50,000+ graduates globally'

  return (
    <footer className="bg-slate-950 text-slate-400 border-t border-slate-900 pt-16 pb-12">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid gap-12 sm:grid-cols-2 lg:grid-cols-5">
          {/* Brand & About (Spans 2 columns on lg) */}
          <div className="lg:col-span-2">
            <Link to="/" className="flex items-center gap-2.5 group inline-flex">
              <div className="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center text-white font-black text-lg shadow-md shadow-blue-500/20">
                M
              </div>
              <span className="text-2xl font-extrabold tracking-tight text-white">
                {siteName}
              </span>
            </Link>

            <p className="mt-4 text-sm leading-relaxed text-slate-400 max-w-sm">
              {footerText}
            </p>

            <div className="mt-6 flex items-center gap-3">
              {settings.social_twitter && (
                <a
                  href={settings.social_twitter}
                  target="_blank"
                  rel="noreferrer"
                  aria-label="Twitter"
                  className="w-9 h-9 rounded-xl bg-slate-900 hover:bg-blue-600 text-slate-300 hover:text-white flex items-center justify-center text-sm font-bold transition shadow-xs"
                >
                  𝕏
                </a>
              )}
              {settings.social_linkedin && (
                <a
                  href={settings.social_linkedin}
                  target="_blank"
                  rel="noreferrer"
                  aria-label="LinkedIn"
                  className="w-9 h-9 rounded-xl bg-slate-900 hover:bg-blue-600 text-slate-300 hover:text-white flex items-center justify-center text-sm font-bold transition shadow-xs"
                >
                  in
                </a>
              )}
              {settings.social_youtube && (
                <a
                  href={settings.social_youtube}
                  target="_blank"
                  rel="noreferrer"
                  aria-label="YouTube"
                  className="w-9 h-9 rounded-xl bg-slate-900 hover:bg-red-600 text-slate-300 hover:text-white flex items-center justify-center text-sm font-bold transition shadow-xs"
                >
                  ▶
                </a>
              )}
              {settings.social_github && (
                <a
                  href={settings.social_github}
                  target="_blank"
                  rel="noreferrer"
                  aria-label="GitHub"
                  className="w-9 h-9 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white flex items-center justify-center text-sm font-bold transition shadow-xs"
                >
                  ⌘
                </a>
              )}
            </div>

            <div className="mt-6 flex items-center gap-2 text-xs font-semibold text-emerald-400 bg-emerald-950/40 border border-emerald-900/50 py-1.5 px-3 rounded-full w-fit">
              <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
              {badgeText}
            </div>
          </div>

          {/* Quick Learning Links */}
          <div>
            <h3 className="text-xs font-bold uppercase tracking-widest text-slate-200 mb-4">
              Learning
            </h3>
            <ul className="space-y-2.5">
              {quickLinks.map((link) => (
                <li key={link.label}>
                  <Link
                    to={link.to || '/courses'}
                    className="text-sm text-slate-400 hover:text-blue-400 transition"
                  >
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Programs / Domains */}
          <div>
            <h3 className="text-xs font-bold uppercase tracking-widest text-slate-200 mb-4">
              Top Domains
            </h3>
            <ul className="space-y-2.5">
              {FALLBACK_DOMAINS.map((cat) => (
                <li key={cat.label}>
                  <Link
                    to={cat.to || '/courses'}
                    className="text-sm text-slate-400 hover:text-blue-400 transition"
                  >
                    {cat.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Support */}
          <div>
            <h3 className="text-xs font-bold uppercase tracking-widest text-slate-200 mb-4">
              Advisory Desk
            </h3>
            <ul className="space-y-2.5">
              {supportLinks.map((item) => (
                <li key={item.label}>
                  <Link
                    to={item.to || '/contact'}
                    className="text-sm text-slate-400 hover:text-blue-400 transition"
                  >
                    {item.label}
                  </Link>
                </li>
              ))}
            </ul>

            {settings.contact_phone && (
              <div className="mt-4 pt-3 border-t border-slate-900">
                <p className="text-[11px] text-slate-500 font-bold uppercase">Admissions Hotline:</p>
                <p className="text-xs text-white font-mono mt-0.5">{settings.contact_phone}</p>
              </div>
            )}
          </div>
        </div>

        {/* Bottom copyright line */}
        <div className="mt-12 pt-8 border-t border-slate-900 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 gap-4">
          <p>{copyrightText}</p>
          <div className="flex gap-6">
            <Link to="/about" className="hover:text-slate-400 transition">About</Link>
            <Link to="/faq" className="hover:text-slate-400 transition">FAQ</Link>
            <Link to="/contact" className="hover:text-slate-400 transition">Admissions</Link>
            <Link to="/verify-certificate" className="hover:text-slate-400 transition">Verify Certificate</Link>
          </div>
        </div>
      </div>
    </footer>
  )
}