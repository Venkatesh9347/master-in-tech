import { Link } from 'react-router-dom'

export default function CourseDetails() {
  return (
    <main className="min-h-[calc(100vh-80px)] bg-slate-50 py-16">
      <section className="mx-auto max-w-4xl px-6 lg:px-8">
        <Link
          to="/courses"
          className="font-semibold text-blue-600 transition hover:text-blue-800"
        >
          ← Back to courses
        </Link>

        <div className="mt-6 rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200 sm:p-12">
          <p className="font-semibold uppercase tracking-[0.2em] text-blue-600">
            Development Program
          </p>

          <h1 className="mt-4 text-4xl font-bold text-slate-900">
            Full Stack Development
          </h1>

          <p className="mt-6 text-lg leading-8 text-slate-600">
            Learn to build modern web applications from the frontend to the
            database using React, Laravel, and PostgreSQL.
          </p>

          <div className="mt-8 grid gap-6 border-y border-slate-200 py-8 sm:grid-cols-2">
            <div>
              <p className="text-sm font-semibold text-slate-500">Duration</p>
              <p className="mt-1 text-lg font-bold text-slate-900">6 Months</p>
            </div>

            <div>
              <p className="text-sm font-semibold text-slate-500">Format</p>
              <p className="mt-1 text-lg font-bold text-slate-900">
                Live instructor-led training
              </p>
            </div>
          </div>

          <h2 className="mt-8 text-2xl font-bold text-slate-900">
            What you will learn
          </h2>

          <ul className="mt-4 space-y-3 text-slate-600">
            <li>• React and TypeScript fundamentals</li>
            <li>• Laravel REST APIs and authentication</li>
            <li>• PostgreSQL database design</li>
            <li>• Building and deploying complete web applications</li>
          </ul>

          <Link
            to="/login"
            className="mt-10 inline-flex rounded-lg bg-blue-600 px-6 py-3 font-semibold text-white transition hover:bg-blue-700"
          >
            Enquire about this course
          </Link>
        </div>
      </section>
    </main>
  )
}