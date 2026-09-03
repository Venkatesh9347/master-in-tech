import { useEffect, useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import API from '../services/api';
import { useAuth } from '../context/useAuth';
import Navbar from '../components/Navbar';
import EventGrid from '../components/events/EventGrid';
import EventRegistrationModal from '../components/events/EventRegistrationModal';
import type { Event } from '../types/event';

type EventFilter = 'all' | 'upcoming' | 'past';

export default function Events() {
  const { user } = useAuth();
  const [events, setEvents] = useState<Event[]>([]);
  const [filteredEvents, setFilteredEvents] = useState<Event[]>([]);
  const [filter, setFilter] = useState<EventFilter>('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [registrationModal, setRegistrationModal] = useState<{
    isOpen: boolean;
    event: Event | null;
  }>({ isOpen: false, event: null });
  const [registrationLoading, setRegistrationLoading] = useState(false);
  const [registrationError, setRegistrationError] = useState('');

  const filterEventsByType = useCallback((eventList: Event[], type: EventFilter) => {
    const now = new Date();
    let filtered: Event[];

    if (type === 'upcoming') {
      filtered = eventList.filter((e) => new Date(e.event_date) > now);
    } else if (type === 'past') {
      filtered = eventList.filter((e) => new Date(e.event_date) <= now);
    } else {
      filtered = eventList;
    }

    setFilteredEvents(filtered.sort((a, b) =>
      new Date(a.event_date).getTime() - new Date(b.event_date).getTime()
    ));
  }, []);

  const fetchEvents = useCallback(() => {
    setLoading(true);
    setError('');
    API.get<Event[]>('/events')
      .then((response) => {
        setEvents(response.data);
        filterEventsByType(response.data, 'all');
      })
      .catch(() => {
        setError('Failed to load events');
      })
      .finally(() => setLoading(false));
  }, [filterEventsByType]);

  // Fetch events on mount
  useEffect(() => {
    fetchEvents();
  }, [fetchEvents]);

  const handleFilterChange = (newFilter: EventFilter) => {
    setFilter(newFilter);
    filterEventsByType(events, newFilter);
  };

  const handleRegisterClick = (eventId: number) => {
    if (!user) {
      window.location.href = '/login';
      return;
    }
    const event = events.find((e) => e.id === eventId) || null;
    setRegistrationModal({ isOpen: true, event });
    setRegistrationError('');
  };

  const handleConfirmRegistration = () => {
    if (!registrationModal.event) return;

    setRegistrationLoading(true);
    setRegistrationError('');

    API.post(`/events/${registrationModal.event.id}/register`)
      .then(() => {
        // Close modal and refresh events
        setRegistrationModal({ isOpen: false, event: null });
        fetchEvents();
      })
      .catch((err) => {
        setRegistrationError(
          err.response?.data?.message || 'Failed to register for event'
        );
      })
      .finally(() => setRegistrationLoading(false));
  };

  return (
    <div className="min-h-screen bg-slate-50">
      <Navbar />

      {/* Hero Section */}
      <section className="bg-gradient-to-br from-blue-600 to-blue-800 text-white py-16">
        <div className="max-w-7xl mx-auto px-6">
          <h1 className="text-5xl font-bold mb-4">Master In Tech Events</h1>
          <p className="text-xl text-blue-100 mb-8">
            Learn from industry professionals, explore emerging technologies, and build practical skills.
          </p>
          <div className="flex gap-4">
            <button
              onClick={() => {
                const eventsSection = document.getElementById('events-section');
                eventsSection?.scrollIntoView({ behavior: 'smooth' });
              }}
              className="bg-white text-blue-600 px-8 py-3 rounded-lg font-bold hover:bg-blue-50 transition"
            >
              Explore Events
            </button>
            <Link
              to="/courses"
              className="bg-transparent border-2 border-white text-white px-8 py-3 rounded-lg font-bold hover:bg-white hover:text-blue-600 transition"
            >
              Browse Programs
            </Link>
          </div>
        </div>
      </section>

      {/* Events Section */}
      <section id="events-section" className="py-16">
        <div className="max-w-7xl mx-auto px-6">
          <div className="mb-8">
            <h2 className="text-3xl font-bold text-slate-900 mb-6">Featured Events</h2>

            {/* Filter Tabs */}
            <div className="flex gap-2 border-b border-slate-200">
              <button
                onClick={() => handleFilterChange('all')}
                className={`px-4 py-3 font-semibold border-b-2 transition ${
                  filter === 'all'
                    ? 'border-blue-600 text-blue-600'
                    : 'border-transparent text-slate-600 hover:text-slate-900'
                }`}
              >
                All Events
              </button>
              <button
                onClick={() => handleFilterChange('upcoming')}
                className={`px-4 py-3 font-semibold border-b-2 transition ${
                  filter === 'upcoming'
                    ? 'border-blue-600 text-blue-600'
                    : 'border-transparent text-slate-600 hover:text-slate-900'
                }`}
              >
                Upcoming
              </button>
              <button
                onClick={() => handleFilterChange('past')}
                className={`px-4 py-3 font-semibold border-b-2 transition ${
                  filter === 'past'
                    ? 'border-blue-600 text-blue-600'
                    : 'border-transparent text-slate-600 hover:text-slate-900'
                }`}
              >
                Past Events
              </button>
            </div>
          </div>

          {error && (
            <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
              {error}
            </div>
          )}

          {loading ? (
            <div className="text-center py-12">
              <p className="text-slate-600">Loading events...</p>
            </div>
          ) : (
            <EventGrid
              events={filteredEvents}
              onRegister={handleRegisterClick}
              emptyMessage={`No ${filter !== 'all' ? filter : ''} events found`}
            />
          )}
        </div>
      </section>

      {/* Benefits Section */}
      <section className="bg-white py-16 border-t border-slate-200">
        <div className="max-w-7xl mx-auto px-6">
          <h2 className="text-3xl font-bold text-slate-900 mb-12 text-center">
            Why Join Master In Tech Events
          </h2>

          <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { icon: '👨‍💼', title: 'Industry Experts', desc: 'Learn from professionals with real-world experience' },
              { icon: '🔴', title: 'Live Learning', desc: 'Interactive sessions with Q&A opportunities' },
              { icon: '🛠️', title: 'Practical Projects', desc: 'Work on hands-on projects during events' },
              { icon: '🏆', title: 'Certificates', desc: 'Earn recognized certificates upon completion' },
            ].map((benefit, idx) => (
              <div key={idx} className="text-center p-6 rounded-lg bg-slate-50">
                <div className="text-4xl mb-4">{benefit.icon}</div>
                <h3 className="font-bold text-slate-900 mb-2">{benefit.title}</h3>
                <p className="text-sm text-slate-600">{benefit.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA Section */}
      <section className="bg-gradient-to-r from-blue-600 to-blue-800 text-white py-16">
        <div className="max-w-7xl mx-auto px-6 text-center">
          <h2 className="text-3xl font-bold mb-4">Ready to Start Learning?</h2>
          <p className="text-xl text-blue-100 mb-8">
            Join Master In Tech and explore our comprehensive courses
          </p>
          <Link
            to="/courses"
            className="inline-block bg-white text-blue-600 px-8 py-3 rounded-lg font-bold hover:bg-blue-50 transition"
          >
            Explore Courses
          </Link>
        </div>
      </section>

      {/* Registration Modal */}
      <EventRegistrationModal
        isOpen={registrationModal.isOpen}
        event={registrationModal.event}
        isLoading={registrationLoading}
        error={registrationError}
        onConfirm={handleConfirmRegistration}
        onClose={() => setRegistrationModal({ isOpen: false, event: null })}
      />
    </div>
  );
}
