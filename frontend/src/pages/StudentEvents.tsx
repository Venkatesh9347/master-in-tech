import { useEffect, useState, useCallback } from 'react'
import { Link } from 'react-router-dom'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'
import EventGrid from '../components/events/EventGrid'
import type { Event } from '../types/event'

type EventFilter = 'registered' | 'upcoming' | 'completed'

export default function StudentEvents() {
  const [registeredEvents, setRegisteredEvents] = useState<Event[]>([])
  const [upcomingEvents, setUpcomingEvents] = useState<Event[]>([])
  const [completedEvents, setCompletedEvents] = useState<Event[]>([])
  const [filter, setFilter] = useState<EventFilter>('registered')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const organizeEvents = useCallback((eventList: Event[]) => {
    const now = new Date()
    const upcoming = eventList.filter((e) => new Date(e.event_date) > now)
    const completed = eventList.filter((e) => new Date(e.event_date) <= now)

    setUpcomingEvents(
      upcoming.sort(
        (a, b) => new Date(a.event_date).getTime() - new Date(b.event_date).getTime()
      )
    )
    setCompletedEvents(
      completed.sort(
        (a, b) => new Date(b.event_date).getTime() - new Date(a.event_date).getTime()
      )
    )
  }, [])

  const fetchStudentEvents = useCallback(() => {
    API.get<Event[]>('/my-events')
      .then((response) => {
        const events = Array.isArray(response.data) ? response.data : []
        setRegisteredEvents(events)
        organizeEvents(events)
      })
      .catch(() => {
        setError('Failed to load your registered masterclasses.')
      })
      .finally(() => setLoading(false))
  }, [organizeEvents])

  useEffect(() => {
    fetchStudentEvents()
  }, [fetchStudentEvents])

  const getDisplayEvents = (): Event[] => {
    switch (filter) {
      case 'registered':
        return registeredEvents
      case 'upcoming':
        return upcomingEvents
      case 'completed':
        return completedEvents
      default:
        return registeredEvents
    }
  }

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-500 selection:text-white">
      <Navbar />

      <main className="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full space-y-8">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <span className="text-xs font-extrabold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full">
              Live Learning
            </span>
            <h1 className="text-3xl font-black text-slate-900 mt-2">
              My Registered Masterclasses
            </h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Access upcoming technical workshops, live coding webinars, and past session recordings.
            </p>
          </div>

          <Link
            to="/events"
            className="px-5 py-2.5 rounded-xl font-bold text-xs bg-blue-600 hover:bg-blue-700 text-white transition shadow-sm self-start sm:self-auto"
          >
            + Browse Masterclass Schedule
          </Link>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div className="p-6 bg-white rounded-3xl border border-slate-200/80 shadow-xs">
            <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Registered</p>
            <p className="text-3xl font-black text-slate-900 mt-1">{registeredEvents.length}</p>
          </div>
          <div className="p-6 bg-white rounded-3xl border border-slate-200/80 shadow-xs">
            <p className="text-[11px] font-bold text-blue-600 uppercase tracking-wider">Upcoming Live</p>
            <p className="text-3xl font-black text-blue-600 mt-1">{upcomingEvents.length}</p>
          </div>
          <div className="p-6 bg-white rounded-3xl border border-slate-200/80 shadow-xs">
            <p className="text-[11px] font-bold text-emerald-600 uppercase tracking-wider">Completed Sessions</p>
            <p className="text-3xl font-black text-emerald-600 mt-1">{completedEvents.length}</p>
          </div>
        </div>

        {/* Filter Tabs */}
        <div className="border-b border-slate-200 flex gap-2">
          {[
            { id: 'registered', label: 'All Registered', count: registeredEvents.length },
            { id: 'upcoming', label: 'Upcoming', count: upcomingEvents.length },
            { id: 'completed', label: 'Past Sessions', count: completedEvents.length },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setFilter(tab.id as EventFilter)}
              className={`px-4 py-3 font-bold text-xs border-b-2 transition flex items-center gap-1.5 ${
                filter === tab.id
                  ? 'border-blue-600 text-blue-600'
                  : 'border-transparent text-slate-600 hover:text-slate-900'
              }`}
            >
              <span>{tab.label}</span>
              <span className="text-[10px] bg-slate-100 px-2 py-0.5 rounded-full font-bold">
                {tab.count}
              </span>
            </button>
          ))}
        </div>

        {/* Error Message */}
        {error && (
          <div className="p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-xs font-bold">
            {error}
          </div>
        )}

        {/* Events Grid */}
        {loading ? (
          <div className="text-center py-16">
            <p className="text-xs text-slate-500 font-semibold">Loading your registered workshops...</p>
          </div>
        ) : (
          <div className="space-y-8">
            <EventGrid
              events={getDisplayEvents()}
              showRegisterButton={false}
              emptyMessage={
                filter === 'registered'
                  ? "You haven't registered for any live events yet."
                  : filter === 'upcoming'
                  ? 'No upcoming live masterclasses scheduled.'
                  : 'No past completed events found.'
              }
            />

            {registeredEvents.length === 0 && filter === 'registered' && (
              <div className="p-10 bg-white rounded-3xl text-center border border-slate-200/80 shadow-xs max-w-lg mx-auto">
                <span className="text-4xl mb-2 block">🎙️</span>
                <h3 className="text-base font-bold text-slate-900 mb-1">
                  Explore Live Tech Masterclasses
                </h3>
                <p className="text-xs text-slate-500 mb-6 leading-relaxed">
                  Join live interactive webinars led by senior practitioners from Google, Microsoft, and AWS.
                </p>
                <Link
                  to="/events"
                  className="px-6 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700 transition shadow-sm"
                >
                  Browse Upcoming Schedule →
                </Link>
              </div>
            )}
          </div>
        )}
      </main>

      <Footer />
    </div>
  )
}
