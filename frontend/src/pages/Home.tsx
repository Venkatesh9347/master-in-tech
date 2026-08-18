import { Link } from 'react-router-dom'

export default function Home() {
  return (
    <main className="min-h-[calc(100vh-80px)] bg-slate-50">
      <section className="mx-auto grid max-w-7xl gap-12 px-6 py-20 lg:grid-cols-2 lg:items-center lg:px-8">
        <div>
          <p className="mb-4 font-semibold uppercase tracking-[0.2em] text-blue-600">
            Learn. Build. Grow.
          </p>

          <h1 className="text-4xl font-bold leading-tight text-slate-900 sm:text-5xl">
            Build a career in technology with Master In Tech.
          </h1>

          <p className="mt-6 max-w-xl text-lg leading-8 text-slate-600">
            Practical, instructor-led courses in Data Science, AI/ML, Cloud
            Computing, Full Stack Development, SAP, and more.
          </p>

          <div className="mt-8 flex flex-wrap gap-4">
            <Link
              to="/courses"
              className="rounded-lg bg-blue-600 px-6 py-3 font-semibold text-white transition hover:bg-blue-700"
            >
              Explore Courses
            </Link>

            <Link
              to="/login"
              className="rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-700 transition hover:bg-slate-100"
            >
              Student Login
            </Link>
          </div>
        </div>

        <div className="rounded-3xl bg-blue-600 p-8 text-white shadow-xl">
          <p className="text-sm font-semibold uppercase tracking-wider text-blue-100">
            Why learn with us?
          </p>

          <div className="mt-8 grid gap-6 sm:grid-cols-3 lg:grid-cols-1">
            <div>
              <p className="text-3xl font-bold">Live</p>
              <p className="mt-1 text-blue-100">Interactive training sessions</p>
            </div>

            <div>
              <p className="text-3xl font-bold">1-to-1</p>
              <p className="mt-1 text-blue-100">Personal learning support</p>
            </div>

            <div>
              <p className="text-3xl font-bold">Career</p>
              <p className="mt-1 text-blue-100">Placement assistance</p>
            </div>
          </div>
        </div>
      </section>
    </main>
  )
}