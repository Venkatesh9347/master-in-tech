import { Link } from 'react-router-dom'
const courses = [
  {
    title: 'Full Stack Development',
    description: 'Build modern web applications with React, Laravel, and PostgreSQL.',
    duration: '6 Months',
    category: 'Development',
  },
  {
    title: 'Data Science & AI',
    description: 'Learn Python, data analysis, machine learning, and AI foundations.',
    duration: '6 Months',
    category: 'Data',
  },
  {
    title: 'Cloud & DevOps',
    description: 'Understand cloud platforms, Docker, CI/CD, and deployment workflows.',
    duration: '4 Months',
    category: 'Cloud',
  },
  {
    title: 'Data Engineering',
    description: 'Design data pipelines, work with databases, and manage large datasets.',
    duration: '5 Months',
    category: 'Data',
  },
  {
    title: 'SAP FICO',
    description: 'Learn the fundamentals of SAP financial accounting and controlling.',
    duration: '4 Months',
    category: 'SAP',
  },
  {
    title: 'Software Testing',
    description: 'Build practical skills in manual testing and test automation.',
    duration: '3 Months',
    category: 'Testing',
  },
]

export default function Courses() {
  return (
    <main className="min-h-[calc(100vh-80px)] bg-slate-50 py-16">
      <section className="mx-auto max-w-7xl px-6 lg:px-8">
        <p className="font-semibold uppercase tracking-[0.2em] text-blue-600">
          Master In Tech Programs
        </p>

        <div className="mt-4 max-w-2xl">
          <h1 className="text-4xl font-bold text-slate-900">
            Explore our courses
          </h1>
          <p className="mt-4 text-lg leading-8 text-slate-600">
            Industry-focused training designed to help you build practical,
            job-ready technology skills.
          </p>
        </div>

        <div className="mt-10 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
          {courses.map((course) => (
            <article
              key={course.title}
              className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-lg"
            >
              <div className="flex items-center justify-between gap-4">
                <span className="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">
                  {course.category}
                </span>
                <span className="text-sm font-medium text-slate-500">
                  {course.duration}
                </span>
              </div>

              <h2 className="mt-6 text-2xl font-bold text-slate-900">
                {course.title}
              </h2>

              <p className="mt-3 flex-1 leading-7 text-slate-600">
                {course.description}
              </p>

              <button>
               {course.title === 'Full Stack Development' ? (
  <Link
    to="/courses/full-stack-development"
    className="mt-6 w-fit font-semibold text-blue-600 transition hover:text-blue-800"
  >
    View program details →
  </Link>
) : (
  <span className="mt-6 w-fit font-semibold text-slate-400">
    Details coming soon
  </span>
)}
              </button>
            </article>
          ))}
        </div>
      </section>
    </main>
  )
}