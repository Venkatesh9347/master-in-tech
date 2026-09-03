import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import API from '../services/api';
import { useAuth } from '../context/useAuth';
import Navbar from '../components/Navbar';
import SpeakerCard from '../components/events/SpeakerCard';
import EventRegistrationModal from '../components/events/EventRegistrationModal';
import type { Event, EventCheckRegistrationResponse } from '../types/event';

export default function EventDetails() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();

  const [event, setEvent] = useState<Event | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [isRegistered, setIsRegistered] = useState(false);
  const [showModal, setShowModal] = useState(false);
  const [registrationLoading, setRegistrationLoading] = useState(false);
  const [registrationError, setRegistrationError] = useState('');

  const fetchEventDetails = useCallback(() => {
    if (!id) return;

    API.get<Event>(`/events/${id}`)
      .then((response) => {
        setEvent(response.data);
      })
      .catch(() => {
        setError('Failed to load event details');
      })
      .finally(() => setLoading(false));
  }, [id]);

  const checkRegistration = useCallback(() => {
    if (!user || !event) return;

    API.get<EventCheckRegistrationResponse>(`/events/${event.id}/registration`)
      .then((response) => {
        setIsRegistered(response.data.registered);
      })
      .catch(() => {
        setIsRegistered(false);
      });
  }, [event, user]);

  useEffect(() => {
    fetchEventDetails();
  }, [fetchEventDetails]);

  useEffect(() => {
    if (user && event) {
      checkRegistration();
    }
  }, [user, event, checkRegistration]);

  const handleRegisterClick = () => {
    if (!user) {
      navigate('/login');
      return;
    }
    setShowModal(true);
    setRegistrationError('');
  };

  const handleConfirmRegistration = () => {
    if (!event) return;

    setRegistrationLoading(true);
    setRegistrationError('');

    API.post(`/events/${event.id}/register`)
      .then(() => {
        setShowModal(false);
        setIsRegistered(true);
      })
      .catch((err) => {
        setRegistrationError(
          err.response?.data?.message || 'Failed to register for event'
        );
      })
      .finally(() => setRegistrationLoading(false));
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50">
        <Navbar />
        <div className="flex items-center justify-center h-96">
          <p className="text-slate-600">Loading event details...</p>
        </div>
      </div>
    );
  }

  if (error || !event) {
    return (
      <div className="min-h-screen bg-slate-50">
        <Navbar />
        <div className="max-w-7xl mx-auto px-6 py-12 text-center">
          <p className="text-red-600 text-lg">{error || 'Event not found'}</p>
          <button
            onClick={() => navigate('/events')}
            className="mt-6 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
          >
            Back to Events
          </button>
        </div>
      </div>
    );
  }

  const eventDate = new Date(event.event_date);
  const isCompleted = event.status === 'completed' || eventDate < new Date();
  const isFull = event.registration_limit
    ? event.registered_count >= event.registration_limit
    : false;

  return (
    <div className="min-h-screen bg-slate-50">
      <Navbar />

      {/* Event Banner */}
      <div className="relative h-96 bg-gradient-to-br from-blue-600 to-blue-800">
        {event.banner ? (
          <img
            src={event.banner}
            alt={event.title}
            className="w-full h-full object-cover"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center bg-blue-600">
            <span className="text-white text-2xl">Event Banner</span>
          </div>
        )}
      </div>

      {/* Content */}
      <div className="max-w-4xl mx-auto px-6 py-12">
        {/* Title and Meta */}
        <div className="mb-8">
          <div className="flex items-center gap-3 mb-4">
            <span className="inline-block px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-sm font-semibold uppercase">
              {event.category}
            </span>
            <span className="text-sm text-slate-600">
              {event.registered_count} Registered
            </span>
          </div>

          <h1 className="text-4xl font-bold text-slate-900 mb-4">{event.title}</h1>
          <p className="text-lg text-slate-600 mb-6">{event.description}</p>

          {/* Key Details */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8 pb-8 border-b border-slate-200">
            <div>
              <p className="text-sm text-slate-600 mb-1">Date</p>
              <p className="font-semibold text-slate-900">
                {eventDate.toLocaleDateString('en-US', {
                  weekday: 'long',
                  year: 'numeric',
                  month: 'long',
                  day: 'numeric',
                })}
              </p>
            </div>
            <div>
              <p className="text-sm text-slate-600 mb-1">Time</p>
              <p className="font-semibold text-slate-900">
                {event.start_time} - {event.end_time}
              </p>
            </div>
            <div>
              <p className="text-sm text-slate-600 mb-1">Duration</p>
              <p className="font-semibold text-slate-900">{event.duration} minutes</p>
            </div>
            <div>
              <p className="text-sm text-slate-600 mb-1">Mode</p>
              <p className="font-semibold text-slate-900 capitalize">
                {event.mode === 'online' ? '💻 Online' : '📍 Offline'}
              </p>
            </div>
          </div>
        </div>

        {/* Speaker */}
        <div className="mb-12 pb-12 border-b border-slate-200">
          <h2 className="text-2xl font-bold text-slate-900 mb-8">Instructor</h2>
          <SpeakerCard
            name={event.speaker_name}
            designation={event.speaker_designation}
            image={event.speaker_image}
          />
        </div>

        {/* Event Details Grid */}
        <div className="grid gap-8 lg:grid-cols-3 mb-12">
          {/* Left Content */}
          <div className="lg:col-span-2 space-y-8">
            {/* What You Will Learn */}
            <section>
              <h2 className="text-2xl font-bold text-slate-900 mb-4">What You Will Learn</h2>
              <ul className="space-y-2">
                {[
                  'Understand industry best practices and trends',
                  'Learn practical techniques from real-world scenarios',
                  'Gain insights from experienced professionals',
                  'Network with like-minded individuals',
                  'Access exclusive learning resources',
                ].map((item, idx) => (
                  <li key={idx} className="flex items-start gap-3">
                    <span className="text-blue-600 font-bold mt-1">✓</span>
                    <span className="text-slate-700">{item}</span>
                  </li>
                ))}
              </ul>
            </section>

            {/* Event Agenda */}
            <section>
              <h2 className="text-2xl font-bold text-slate-900 mb-4">Event Agenda</h2>
              <div className="space-y-4">
                {[
                  { time: event.start_time, title: 'Event Begins', desc: 'Welcome & Introductions' },
                  { time: `${event.start_time}+30min`, title: 'Main Session', desc: event.title },
                  { time: `${event.end_time}-15min`, title: 'Q&A Session', desc: 'Interactive discussion with the speaker' },
                  { time: event.end_time, title: 'Event Concludes', desc: 'Thank you and resources' },
                ].map((item, idx) => (
                  <div key={idx} className="flex gap-4">
                    <div className="flex flex-col items-center">
                      <div className="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">
                        {idx + 1}
                      </div>
                      {idx < 3 && <div className="w-0.5 h-12 bg-blue-200 mt-2" />}
                    </div>
                    <div className="pt-2 pb-4">
                      <p className="text-xs text-blue-600 font-semibold uppercase">{item.time}</p>
                      <p className="font-bold text-slate-900">{item.title}</p>
                      <p className="text-sm text-slate-600">{item.desc}</p>
                    </div>
                  </div>
                ))}
              </div>
            </section>

            {/* FAQ */}
            <section>
              <h2 className="text-2xl font-bold text-slate-900 mb-4">Frequently Asked Questions</h2>
              <div className="space-y-4">
                {[
                  {
                    q: 'Can I attend the event if I register late?',
                    a: 'Yes, as long as seats are available and the event has not started yet.',
                  },
                  {
                    q: 'Will I receive a certificate?',
                    a: 'Yes, you will receive a certificate of participation after attending the event.',
                  },
                  {
                    q: 'Is the event recorded?',
                    a: 'Recordings will be made available to registered participants after the event.',
                  },
                  {
                    q: 'Can I cancel my registration?',
                    a: 'Yes, you can cancel anytime before the event starts.',
                  },
                ].map((faq, idx) => (
                  <div key={idx} className="p-4 bg-blue-50 rounded-lg">
                    <p className="font-semibold text-slate-900 mb-2">{faq.q}</p>
                    <p className="text-slate-700">{faq.a}</p>
                  </div>
                ))}
              </div>
            </section>
          </div>

          {/* Right Sidebar */}
          <div>
            {/* Price and Registration */}
            <div className="sticky top-6 p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
              <div className="mb-6 pb-6 border-b border-slate-200">
                <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                  Free Community Workshop
                </span>
              </div>

              <div className="mb-6 pb-6 border-b border-slate-200">
                <p className="text-sm text-slate-600 mb-2">Registrations</p>
                <p className="text-xl font-bold text-slate-900">
                  {event.registered_count}
                  {event.registration_limit && `/${event.registration_limit}`}
                </p>
                {isFull && (
                  <p className="text-sm text-red-600 mt-2">Registration is full</p>
                )}
              </div>

              {isRegistered ? (
                <div className="p-4 bg-green-50 border border-green-200 rounded-lg">
                  <p className="font-semibold text-green-700 text-center">
                    ✓ You're Registered!
                  </p>
                  <p className="text-sm text-green-600 text-center mt-2">
                    Check your email for event details
                  </p>
                </div>
              ) : isCompleted ? (
                <button
                  disabled
                  className="w-full py-3 px-4 bg-slate-100 text-slate-400 rounded-lg font-bold cursor-not-allowed"
                >
                  Event Completed
                </button>
              ) : isFull ? (
                <button
                  disabled
                  className="w-full py-3 px-4 bg-slate-100 text-slate-400 rounded-lg font-bold cursor-not-allowed"
                >
                  Registration Full
                </button>
              ) : (
                <button
                  onClick={handleRegisterClick}
                  className="w-full py-3 px-4 bg-blue-600 text-white rounded-lg font-bold hover:bg-blue-700 transition"
                >
                  Register Now
                </button>
              )}

              {event.mode === 'online' && event.meeting_url && (
                <a
                  href={event.meeting_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="block mt-4 py-2 px-4 text-center text-blue-600 border border-blue-300 rounded-lg font-semibold hover:bg-blue-50 transition"
                >
                  Join Meeting
                </a>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Registration Modal */}
      <EventRegistrationModal
        isOpen={showModal}
        event={event}
        isLoading={registrationLoading}
        error={registrationError}
        onConfirm={handleConfirmRegistration}
        onClose={() => setShowModal(false)}
      />
    </div>
  );
}
