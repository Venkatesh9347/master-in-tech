import { useEffect, useState } from 'react'

type ApiStatus = 'checking' | 'connected' | 'unavailable'

export default function Dashboard() {
  const [apiStatus, setApiStatus] = useState<ApiStatus>('checking')

  useEffect(() => {
    fetch('http://127.0.0.1:8000/api/health')
      .then((response) => {
        if (!response.ok) {
          throw new Error('API request failed')
        }

        return response.json()
      })
      .then(() => setApiStatus('connected'))
      .catch(() => setApiStatus('unavailable'))
  }, [])

  const statusStyles = {
    checking: 'bg-amber-100 text-amber-800',
    connected: 'bg-emerald-100 text-emerald-800',
    unavailable: 'bg-red-100 text-red-800',
  }

  const statusText = {
    checking: 'Checking Laravel API…',
    connected: 'Laravel API connected',
    unavailable: 'Laravel API unavailable',
  }

  return (
    <main className="min-h-[calc(100vh-80px)] bg-slate-50 py-12">
      <section className="mx-auto max-w-7xl px-6 lg:px-8">
        <div className="rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-700 p-8 text-white shadow-lg">
          <p className="text-sm font-semibold uppercase tracking-[0.2em] text-blue-100">
            Student portal
          </p>
          <h1 className="mt-3 text-3xl font-bold">Welcome back, Student!</h1>
          <p className="mt-2 text-blue-100">
            Track your learning progress and enrolled courses.
          </p>

          <span
            className={`mt-6 inline-flex rounded-full px-4 py-2 text-sm font-semibold ${statusStyles[apiStatus]}`}
          >
            {statusText[apiStatus]}
          </span>
        </div>

        <div className="mt-8 grid gap-6 md:grid-cols-3">
          <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p className="text-sm font-semibold text-slate-500">Enrolled Courses</p>
            <p className="mt-2 text-3xl font-bold text-slate-900">2</p>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p className="text-sm font-semibold text-slate-500">Hours Learned</p>
            <p className="mt-2 text-3xl font-bold text-slate-900">38.5</p>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p className="text-sm font-semibold text-slate-500">Certificates</p>
            <p className="mt-2 text-3xl font-bold text-slate-900">1</p>
          </div>
        </div>
      </section>
    </main>
  )
}