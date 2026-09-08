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

export default function Instructors() {
  const [instructors, setInstructors] = useState<InstructorItem[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    API.get<InstructorItem[]>('/public/instructors')
      .then((res) => {
        if (Array.isArray(res.data)) {
          setInstructors(res.data)
        }
      })
      .catch(() => {
        // Leave the directory empty on failure.
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

        {!loading && instructors.length === 0 && (
          <div className="text-center py-20">
            <p className="text-3xl">👨‍🏫</p>
            <p className="text-sm font-semibold text-slate-600 mt-4">
              Our faculty directory is being onboarded.
            </p>
            <p className="text-xs text-slate-400 mt-1">
              Mentor profiles will appear here as they are announced.
            </p>
          </div>
        )}

        <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
          {instructors.map((instructor) => {
            const displayTitle = instructor.designation || instructor.title
            const displayGrads = instructor.graduates_count || instructor.graduates
            const displaySkills = Array.isArray(instructor.skills)
              ? instructor.skills
              : Array.isArray(instructor.courses)
              ? instructor.courses
              : []

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
                  {(instructor.rating || displayGrads) && (
                    <p className="text-[11px] text-slate-400 font-medium mb-3">
                      {[instructor.rating, displayGrads].filter(Boolean).join(' • ')}
                    </p>
                  )}

                  <p className="text-xs text-slate-600 leading-relaxed mb-6">
                    {instructor.bio}
                  </p>
                </div>

                <div className="pt-4 border-t border-slate-100">
                  {displaySkills.length > 0 && (
                    <>
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
                    </>
                  )}

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
