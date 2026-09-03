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

const FALLBACK_FAQS: FaqGroup[] = [
  {
    category: 'Programs & Learning Experience',
    items: [
      {
        q: 'How are Master In Tech programs structured?',
        a: 'Our programs combine on-demand structured technical modules with weekly live mentor-led masterclasses, hands-on quizzes, and production-grade capstone coding projects.',
      },
      {
        q: 'Do I need prior programming experience to enroll?',
        a: 'Beginner courses require zero prior background. Intermediate and advanced tracks list explicit technical prerequisites on their syllabus pages.',
      },
      {
        q: 'What is the weekly time commitment required?',
        a: 'Most students dedicate between 6 to 10 hours per week, allowing you to comfortably balance learning alongside full-time work or college studies.',
      },
      {
        q: 'Do I have lifetime access to course recordings and updates?',
        a: 'Yes, once enrolled, you retain permanent lifetime access to the curriculum, code repositories, resources, and future material updates.',
      },
    ],
  },
  {
    category: 'Certifications & Career Desk',
    items: [
      {
        q: 'How does certificate verification work?',
        a: 'Upon achieving 100% completion on all lessons, quizzes, and assignments, our system issues a cryptographically unique certificate code (e.g. MIT-2026-ABC12345) verifiable publicly at /verify-certificate.',
      },
      {
        q: 'What career support is provided to students?',
        a: 'Students in professional bootcamps receive 1-on-1 resume reviews, mock technical interview sessions with senior tech leads, and direct referrals to our network of 100+ hiring partners.',
      },
    ],
  },
  {
    category: 'Admissions & Demo Classes',
    items: [
      {
        q: 'How do I schedule a free live demo & counseling session?',
        a: 'You can book a free live demo directly from any course page. Our academic counselors will schedule a personalized syllabus walkthrough and career counseling session.',
      },
      {
        q: 'How does the admission and onboarding process work?',
        a: 'After attending your free live demo or submitting an enquiry, our admissions advisory team will guide you through learning prerequisites, career roadmaps, and confirm your LMS enrollment.',
      },
    ],
  },
]

export default function FAQ() {
  const [faqGroups, setFaqGroups] = useState<FaqGroup[]>(FALLBACK_FAQS)
  const [loading, setLoading] = useState(true)

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
        // Fallback remains active
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
