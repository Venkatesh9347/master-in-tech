import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import API from '../services/api';
import Navbar from '../components/Navbar';
import type { Event, EventRegistration } from '../types/event';

export default function AdminEventRegistrations() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [event, setEvent] = useState<Event | null>(null);
  const [registrations, setRegistrations] = useState<EventRegistration[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');

  const fetchRegistrations = useCallback(() => {
    if (!id) return;

    API.get(`/admin/events/${id}/registrations`)
      .then((response) => {
        setEvent(response.data.event);
        setRegistrations(response.data.registrations);
      })
      .catch(() => {
        setError('Failed to load registrations');
      })
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => {
    fetchRegistrations();
  }, [fetchRegistrations]);

  const filteredRegistrations = registrations.filter((reg) => {
    const matchesSearch =
      (reg.user?.name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        reg.user?.email?.toLowerCase().includes(searchTerm.toLowerCase())) ??
      false;

    const matchesStatus =
      statusFilter === 'all' || reg.status === statusFilter;

    return matchesSearch && matchesStatus;
  });

  const stats = {
    total: registrations.length,
    registered: registrations.filter((r) => r.status === 'registered').length,
    cancelled: registrations.filter((r) => r.status === 'cancelled').length,
    attended: registrations.filter((r) => r.attendance_status === 'attended').length,
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50">
        <Navbar />
        <div className="flex items-center justify-center h-96">
          <p className="text-slate-600">Loading...</p>
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
            onClick={() => navigate('/admin/events')}
            className="mt-6 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
          >
            Back to Events
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <Navbar />

      <div className="max-w-7xl mx-auto px-6 py-12">
        {/* Header */}
        <div className="mb-8">
          <Link
            to="/admin/events"
            className="text-blue-600 hover:text-blue-700 font-semibold mb-4 inline-block"
          >
            ← Back to Events
          </Link>
          <h1 className="text-4xl font-bold text-slate-900">Event Registrations</h1>
          <p className="text-slate-600 mt-2">{event.title}</p>
        </div>

        {/* Stats */}
        <div className="grid gap-4 sm:grid-cols-4 mb-8">
          <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
            <p className="text-sm text-slate-600 mb-2">Total Registrations</p>
            <p className="text-3xl font-bold text-slate-900">{stats.total}</p>
          </div>
          <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
            <p className="text-sm text-slate-600 mb-2">Active</p>
            <p className="text-3xl font-bold text-slate-900">{stats.registered}</p>
          </div>
          <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
            <p className="text-sm text-slate-600 mb-2">Cancelled</p>
            <p className="text-3xl font-bold text-slate-900">{stats.cancelled}</p>
          </div>
          <div className="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
            <p className="text-sm text-slate-600 mb-2">Attended</p>
            <p className="text-3xl font-bold text-slate-900">{stats.attended}</p>
          </div>
        </div>

        {/* Search and Filter */}
        <div className="grid gap-4 sm:grid-cols-2 mb-6">
          <input
            type="text"
            placeholder="Search by name or email..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
          />
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
          >
            <option value="all">All Status</option>
            <option value="registered">Registered</option>
            <option value="cancelled">Cancelled</option>
            <option value="completed">Completed</option>
          </select>
        </div>

        {/* Registrations Table */}
        {filteredRegistrations.length === 0 ? (
          <div className="p-8 bg-white rounded-lg border border-slate-200 text-center">
            <p className="text-slate-600">
              {searchTerm || statusFilter !== 'all'
                ? 'No registrations match your filters'
                : 'No registrations yet'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto bg-white rounded-lg border border-slate-200 shadow-sm">
            <table className="w-full">
              <thead className="border-b border-slate-200 bg-slate-50">
                <tr>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">
                    Name
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">
                    Email
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">
                    Registered Date
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">
                    Status
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-slate-900">
                    Attendance
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {filteredRegistrations.map((reg) => (
                  <tr key={reg.id} className="hover:bg-slate-50 transition">
                    <td className="px-6 py-4 font-medium text-slate-900">
                      {reg.user?.name || 'Unknown User'}
                    </td>
                    <td className="px-6 py-4 text-slate-700">
                      {reg.user?.email || '-'}
                    </td>
                    <td className="px-6 py-4 text-slate-700">
                      {new Date(reg.registered_at).toLocaleDateString()}
                    </td>
                    <td className="px-6 py-4">
                      <span
                        className={`inline-block px-3 py-1 rounded-full text-xs font-semibold ${
                          reg.status === 'registered'
                            ? 'bg-green-100 text-green-700'
                            : reg.status === 'cancelled'
                              ? 'bg-red-100 text-red-700'
                              : 'bg-blue-100 text-blue-700'
                        }`}
                      >
                        {reg.status}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <span
                        className={`inline-block px-3 py-1 rounded-full text-xs font-semibold ${
                          reg.attendance_status === 'attended'
                            ? 'bg-green-100 text-green-700'
                            : reg.attendance_status === 'absent'
                              ? 'bg-red-100 text-red-700'
                              : 'bg-yellow-100 text-yellow-700'
                        }`}
                      >
                        {reg.attendance_status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Download CSV - Placeholder */}
        {registrations.length > 0 && (
          <div className="mt-6 flex gap-4">
            <button className="px-6 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition">
              📥 Export as CSV
            </button>
            <Link
              to={`/admin/events/${id}/edit`}
              className="px-6 py-2 border border-slate-300 text-slate-900 rounded-lg font-semibold hover:bg-slate-50 transition"
            >
              Edit Event
            </Link>
          </div>
        )}
      </div>
    </div>
  );
}
