import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import API from '../services/api'

interface FaqItem {
  id?: number
  category: string
  question: string
  answer: string
  q?: string
  a?: string
}

interface FaqGroup {
  category: string
  items: { q: string; a: string }[]
}

export default function FAQ() {
  // No hardcoded fallback: CMS-owned answers only. An unreachable API
  // renders an honest notice, never fabricated Q&A.
  const [faqGroups, setFaqGroups] = useState<FaqGroup[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  useEffect(() => {
    API.get<FaqItem[]>('/public/faqs')
      .then((res) => {
        if (Array.isArray(res.data) && res.data.length > 0) {
          const grouped: Record<string, { q: string; a: string }[]> = {}
          res.data.forEach((item) => {
            const cat = item.category || 'General Questions'
            if (!grouped[cat]) grouped[cat] = []
            grouped[cat].push({
              q: item.question || item.q || '',
              a: item.answer || item.a || '',
            })
          })

          const formatted: FaqGroup[] = Object.entries(grouped).map(([category, items]) => ({
            category,
            items,
          }))

          setFaqGroups(formatted)
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
            Help & Knowledge Desk
          </span>
          <h1 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-2">
            Frequently Asked Questions
          </h1>
          <p className="text-sm sm:text-base text-slate-300 mt-3 max-w-2xl mx-auto font-normal leading-relaxed">
            Find immediate answers to questions about our curriculums, live demos, mentoring, and certificates.
          </p>
        </div>
      </section>

      <main className="flex-grow py-16 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 w-full space-y-10">
        {loading && (
          <div className="text-center text-xs font-bold text-slate-400">
            Loading questions & answers...
          </div>
        )}

        {!loading && faqGroups.length === 0 && (
          <div className="text-center bg-white p-10 rounded-3xl border border-slate-200 shadow-sm max-w-md mx-auto">
            <span className="text-3xl mb-3 block">❓</span>
            <h2 className="text-base font-bold text-slate-900 mb-2">
              {loadError ? 'Answers unavailable right now' : 'No questions published yet'}
            </h2>
            <p className="text-xs text-slate-500 mb-6">
              {loadError
                ? 'We could not load the knowledge desk. Please check your connection or contact our advisor desk below.'
                : 'Our team is preparing answers. Please check back soon or contact our advisor desk below.'}
            </p>
          </div>
        )}

        {faqGroups.map((group) => (
          <div key={group.category} className="space-y-4">
            <h2 className="text-base font-extrabold uppercase tracking-wider text-blue-600">
              {group.category}
            </h2>

            <div className="space-y-3">
              {group.items.map((item, idx) => (
                <details
                  key={idx}
                  className="group bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs open:ring-2 open:ring-blue-500/20 transition"
                >
                  <summary className="flex cursor-pointer items-center justify-between font-bold text-sm text-slate-900 list-none">
                    <span>{item.q}</span>
                    <span className="text-blue-600 font-bold text-lg group-open:rotate-45 transition-transform duration-200">
                      +
                    </span>
                  </summary>
                  <p className="mt-3 text-xs sm:text-sm text-slate-600 leading-relaxed pt-2 border-t border-slate-100">
                    {item.a}
                  </p>
                </details>
              ))}
            </div>
          </div>
        ))}

        {/* Still have questions card */}
        <div className="bg-slate-900 text-white rounded-3xl p-8 text-center shadow-xl">
          <h3 className="text-xl font-bold text-white mb-2">Still Have Questions?</h3>
          <p className="text-xs text-slate-300 mb-6 max-w-md mx-auto">
            Our student advisory team is available to help guide your learning decisions.
          </p>
          <Link
            to="/contact"
            className="inline-block px-6 py-2.5 rounded-xl font-bold text-xs bg-blue-600 hover:bg-blue-500 transition shadow-sm text-white"
          >
            Contact Advisor Desk →
          </Link>
        </div>
      </main>

      <Footer />
    </div>
  )
}
