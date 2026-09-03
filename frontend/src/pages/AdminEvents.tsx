import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import API from '../services/api';
import Navbar from '../components/Navbar';
import type { Event, AdminEventStatsResponse } from '../types/event';

export default function AdminEvents() {
  const [events, setEvents] = useState<Event[]>([]);
  const [stats, setStats] = useState<AdminEventStatsResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [searchTerm, setSearchTerm] = useState('');

  useEffect(() => {
    fetchEvents();
    fetchStats();
  }, []);

  const fetchEvents = () => {
    API.get<Event[]>('/admin/events')
      .then((response) => {
        setEvents(response.data);
      })
      .catch(() => {
        setError('Failed to load events');
      })
      .finally(() => setLoading(false));
  };

  const fetchStats = () => {
    API.get<AdminEventStatsResponse>('/admin/events/stats')
      .then((response) => {
        setStats(response.data);
      })
      .catch(() => {
        // Silently fail for stats
      });
  };

  const handleDelete = (id: number) => {
    if (!window.confirm('Are you sure you want to delete this event?')) return;

    API.delete(`/admin/events/${id}`)
      .then(() => {
        setEvents(events.filter((e) => e.id !== id));
      })
      .catch((err) => {
        alert('Failed to delete event: ' + (err.response?.data?.message || 'Unknown error'));
      });
  };

  const filteredEvents = events.filter(
    (event) =>
      event.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
      event.speaker_name.toLowerCase().includes(searchTerm.toLowerCase())
  );

  return (
    <div className="min-h-screen bg-slate-50">
      <Navbar />

      <div className="max-w-7xl mx-auto px-6 py-12">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
          <div>
            <h1 className="text-4xl font-bold text-slate-900">Event Management</h1>
            <p className="text-slate-600 mt-2">Create, edit, and manage events</p>
          </div>
          <Link
            to="/admin/events/new"
            className="px-6 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition"
          >
            + Create Event
          </Link>
        </div>

        {/* Stats Grid */}
        {stats && (
          <div className="grid gap-4 sm:grid-cols-4 mb-8">
            <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
              <p className="text-sm text-slate-600 mb-2">Total Events</p>
              <p className="text-3xl font-bold text-slate-900">{stats.total_events}</p>
            </div>
            <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
              <p className="text-sm text-slate-600 mb-2">Upcoming</p>
              <p className="text-3xl font-bold text-slate-900">{stats.upcoming_events}</p>
            </div>
            <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
              <p className="text-sm text-slate-600 mb-2">Completed</p>
              <p className="text-3xl font-bold text-slate-900">{stats.completed_events}</p>
            </div>
            <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
              <p className="text-sm text-slate-600 mb-2">Total Registrations</p>
              <p className="text-3xl font-bold text-slate-900">{stats.total_registrations}</p>
            </div>
          </div>
        )}

        {/* Error */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
            {error}
          </div>
        )}

        {/* Search */}
        <div className="mb-6">
          <input
            type="text"
            placeholder="Search events by title or speaker..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
          />
        </div>

        {/* Events Table */}
        {loading ? (
          <div className="text-center py-12">
            <p className="text-slate-600">Loading events...</p>
          </div>
        ) : filteredEvents.length === 0 ? (
          <div className="p-8 bg-white rounded-lg border border-slate-200 text-center">
            <p className="text-slate-600 mb-4">
              {searchTerm ? 'No events match your search' : 'No events yet'}
            </p>
            {!searchTerm && (
              <Link
                to="/admin/events/new"
                className="inline-block px-4 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition"
              >
                Create First Event
              </Link>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto bg-white rounded-lg border border-slate-200 shadow-sm">
            <table className="w-full">
              <thead className="border-b border-slate-200 bg-slate-50">
                <tr>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">Title</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">Speaker</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">Date</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">Mode</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">
                    Registrations
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">Status</th>
                  <th className="px-6 py-4 text-right text-sm font-semibold text-slate-900">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {filteredEvents.map((event) => (
                  <tr key={event.id} className="hover:bg-slate-50 transition">
                    <td className="px-6 py-4 font-medium text-slate-900 max-w-xs truncate">
                      {event.title}
                    </td>
                    <td className="px-6 py-4 text-slate-700">{event.speaker_name}</td>
                    <td className="px-6 py-4 text-slate-700">
                      {new Date(event.event_date).toLocaleDateString()}
                    </td>
                    <td className="px-6 py-4 capitalize">
                      <span className="text-sm">
                        {event.mode === 'online' ? '💻' : '📍'} {event.mode}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-slate-700">
                      {event.registered_count}
                      {event.registration_limit && `/${event.registration_limit}`}
                    </td>
                    <td className="px-6 py-4">
                      <span
                        className={`inline-block px-3 py-1 rounded-full text-xs font-semibold ${
                          event.status === 'published'
                            ? 'bg-green-100 text-green-700'
                            : event.status === 'draft'
                              ? 'bg-slate-100 text-slate-700'
                              : event.status === 'completed'
                                ? 'bg-blue-100 text-blue-700'
                                : 'bg-red-100 text-red-700'
                        }`}
                      >
                        {event.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex justify-end gap-2">
                        <Link
                          to={`/admin/events/${event.id}`}
                          className="px-3 py-1 text-sm bg-blue-50 text-blue-600 rounded hover:bg-blue-100 transition"
                        >
                          View
                        </Link>
                        <Link
                          to={`/admin/events/${event.id}/edit`}
                          className="px-3 py-1 text-sm bg-yellow-50 text-yellow-600 rounded hover:bg-yellow-100 transition"
                        >
                          Edit
                        </Link>
                        <Link
                          to={`/admin/events/${event.id}/registrations`}
                          className="px-3 py-1 text-sm bg-purple-50 text-purple-600 rounded hover:bg-purple-100 transition"
                        >
                          Registrations
                        </Link>
                        <button
                          onClick={() => handleDelete(event.id)}
                          className="px-3 py-1 text-sm bg-red-50 text-red-600 rounded hover:bg-red-100 transition"
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
