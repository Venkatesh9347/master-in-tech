import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import API from '../services/api'

interface InstructorItem {
  id?: number
  name: string
  designation?: string | null
  title?: string | null
  company?: string | null
  bio?: string | null
  avatar?: string | null
  rating?: string | null
  graduates_count?: string | null
  graduates?: string | null
  skills?: string[] | null
  courses?: string[]
}

const FALLBACK_INSTRUCTORS: InstructorItem[] = [
  {
    name: 'Sarah Johnson',
    designation: 'Senior Lead Architect',
    company: 'Microsoft',
    bio: '10+ years architecting enterprise web systems and cloud services. Passionate about TypeScript, React, and server actions.',
    avatar: '👩‍💻',
    skills: ['Full Stack Web Development', 'Cloud Computing'],
    rating: '4.9 ★ (320 reviews)',
    graduates_count: '1,400+ students',
  },
  {
    name: 'Aman Verma',
    designation: 'Staff AI Research Lead',
    company: 'Google',
    bio: 'AI researcher and mentor with deep expertise in deep learning, transformer fine-tuning, and scalable inference pipelines.',
    avatar: '👨‍💻',
    skills: ['Python with AI', 'Data Science'],
    rating: '4.9 ★ (410 reviews)',
    graduates_count: '2,100+ students',
  },
  {
    name: 'Neha Patel',
    designation: 'Principal SAP Consultant',
    company: 'SAP Labs',
    bio: 'Certified SAP FICO and enterprise financial reporting veteran with 8+ years leading multinational ERP deployments.',
    avatar: '👩‍💼',
    skills: ['SAP FICO Financial Accounting'],
    rating: '4.8 ★ (190 reviews)',
    graduates_count: '950+ students',
  },
  {
    name: 'Rajesh Kumar',
    designation: 'Principal Cloud Architect',
    company: 'Amazon Web Services',
    bio: 'AWS & Kubernetes certified infra specialist who has guided Fortune 100 enterprise migrations and CI/CD automation.',
    avatar: '👨‍💼',
    skills: ['Cloud Computing', 'DevOps & Infrastructure'],
    rating: '4.9 ★ (280 reviews)',
    graduates_count: '1,600+ students',
  },
  {
    name: 'Priya Sharma',
    designation: 'Staff Data Scientist',
    company: 'Netflix',
    bio: 'Specialist in recommendation systems, experimentation analysis, and high-volume data visualization using Python and SQL.',
    avatar: '👩‍🔬',
    skills: ['Data Science & Analytics', 'AI & Machine Learning'],
    rating: '4.9 ★ (240 reviews)',
    graduates_count: '1,200+ students',
  },
  {
    name: 'Michael Chen',
    designation: 'Senior DevOps Specialist',
    company: 'GitHub',
    bio: 'Automation advocate focused on secure CI/CD pipelines, container orchestration, and developer productivity tooling.',
    avatar: '👨‍🔧',
    skills: ['DevOps & Infrastructure'],
    rating: '4.8 ★ (150 reviews)',
    graduates_count: '880+ students',
  },
]

export default function Instructors() {
  const [instructors, setInstructors] = useState<InstructorItem[]>(FALLBACK_INSTRUCTORS)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    API.get<InstructorItem[]>('/public/instructors')
      .then((res) => {
        if (Array.isArray(res.data) && res.data.length > 0) {
          setInstructors(res.data)
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

      {/* Hero Header */}
      <section className="bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 text-white py-16 border-b border-slate-800 text-center">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          <span className="text-xs font-extrabold uppercase tracking-widest text-blue-400">
            World-Class Mentors
          </span>
          <h1 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-2">
            Meet Our Industry Faculty
          </h1>
          <p className="text-sm sm:text-base text-slate-300 mt-3 max-w-2xl mx-auto leading-relaxed font-normal">
            Learn directly from senior practitioners actively working on high-scale systems at the world's top technology firms.
          </p>
        </div>
      </section>

      <main className="flex-grow py-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full">
        {loading && (
          <div className="text-center text-xs font-bold text-slate-400 mb-6">
            Loading faculty directory...
          </div>
        )}

        <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
          {instructors.map((instructor) => {
            const displayTitle = instructor.designation || instructor.title
            const displayGrads = instructor.graduates_count || instructor.graduates || '1,000+ students'
            const displaySkills = Array.isArray(instructor.skills)
              ? instructor.skills
              : Array.isArray(instructor.courses)
              ? instructor.courses
              : ['Engineering']

            return (
              <div
                key={instructor.name}
                className="rounded-3xl bg-white p-7 border border-slate-200/80 shadow-xs hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between"
              >
                <div>
                  {/* Avatar & Header */}
                  <div className="flex items-start justify-between gap-4 mb-4">
                    <div className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-3xl shadow-md shadow-blue-500/20 text-white">
                      {instructor.avatar || '👨‍🏫'}
                    </div>
                    {instructor.company && (
                      <span className="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-slate-900 text-white shadow-xs">
                        {instructor.company}
                      </span>
                    )}
                  </div>

                  <h2 className="text-lg font-bold text-slate-900">{instructor.name}</h2>
                  {displayTitle && (
                    <p className="text-xs font-semibold text-blue-600 mb-1">{displayTitle}</p>
                  )}
                  <p className="text-[11px] text-slate-400 font-medium mb-3">
                    {instructor.rating || '4.9 ★'} • {displayGrads}
                  </p>

                  <p className="text-xs text-slate-600 leading-relaxed mb-6">
                    {instructor.bio}
                  </p>
                </div>

                <div className="pt-4 border-t border-slate-100">
                  <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">
                    Specialized Programs:
                  </p>
                  <div className="flex flex-wrap gap-1.5 mb-4">
                    {displaySkills.map((skill) => (
                      <span
                        key={skill}
                        className="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 text-[10px] font-semibold border border-blue-200"
                      >
                        {skill}
                      </span>
                    ))}
                  </div>

                  <Link
                    to="/courses"
                    className="block w-full text-center py-2.5 rounded-xl font-bold text-xs text-white bg-blue-600 hover:bg-blue-700 transition shadow-xs"
                  >
                    View Programs →
                  </Link>
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
