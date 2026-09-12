import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import API from '../services/api'

interface ResourceItem {
  id?: number
  title: string
  desc?: string | null
  description?: string | null
  icon?: string | null
  tag?: string | null
  type: string
  url_or_file?: string | null
}

export default function Resources() {
  // CMS-owned resources only. No hardcoded guides: invented titles must
  // never render as a real knowledge base.
  const [resources, setResources] = useState<ResourceItem[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  useEffect(() => {
    API.get<ResourceItem[]>('/public/resources')
      .then((res) => {
        if (Array.isArray(res.data) && res.data.length > 0) {
          setResources(res.data)
        }
      })
      .catch(() => {
        setLoadError(true)
      })
      .finally(() => setLoading(false))
  }, [])

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-500 selection:text-white">
      <Navbar />

      {/* Header */}
      <section className="bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 text-white py-16 border-b border-slate-800 text-center">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          <span className="text-xs font-extrabold uppercase tracking-widest text-blue-400">
            Open Knowledge Base
          </span>
          <h1 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-2">
            Engineering & Learning Resources
          </h1>
          <p className="text-sm sm:text-base text-slate-300 mt-3 max-w-2xl mx-auto font-normal leading-relaxed">
            Free developer roadmaps, technical interview cheat sheets, and starter kits to support your continuous tech growth.
          </p>
        </div>
      </section>

      <main className="flex-grow py-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full">
        {loading && (
          <div className="text-center text-xs font-bold text-slate-400 mb-6">
            Loading knowledge resources...
          </div>
        )}

        {!loading && resources.length === 0 && (
          <div className="text-center bg-white p-10 rounded-3xl border border-slate-200 shadow-sm max-w-md mx-auto">
            <span className="text-3xl mb-3 block">📚</span>
            <h2 className="text-base font-bold text-slate-900 mb-2">
              {loadError ? 'Resources unavailable right now' : 'No resources published yet'}
            </h2>
            <p className="text-xs text-slate-500">
              {loadError
                ? 'We could not load the knowledge base. Please check your connection and try again.'
                : 'Our team is preparing learning resources. Please check back soon.'}
            </p>
          </div>
        )}

        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {resources.map((res) => {
            const summary = res.description || res.desc
            return (
              <div
                key={res.title}
                className="rounded-3xl bg-white p-7 border border-slate-200/80 shadow-xs hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between"
              >
                <div>
                  <div className="flex items-center justify-between mb-4">
                    <div className="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl shadow-inner">
                      {res.icon || '💡'}
                    </div>
                    {res.tag && (
                      <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700">
                        {res.tag}
                      </span>
                    )}
                  </div>

                  <h3 className="text-base font-bold text-slate-900 mb-2 leading-snug">
                    {res.title}
                  </h3>
                  <p className="text-xs text-slate-600 leading-relaxed">
                    {summary}
                  </p>
                </div>

                <div className="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
                  <span className="text-[11px] font-bold text-slate-400 uppercase">
                    {res.type}
                  </span>
                  {res.url_or_file ? (
                    <a
                      href={res.url_or_file}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1"
                    >
                      Access Free →
                    </a>
                  ) : (
                    <Link
                      to="/courses"
                      className="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1"
                    >
                      Access Free →
                    </Link>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </main>

      <Footer />
    </div>
  )
}
