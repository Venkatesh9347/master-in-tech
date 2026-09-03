import { useEffect, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import API from '../services/api';
import Navbar from '../components/Navbar';
import ImageUploadField from '../components/admin/ImageUploadField';
import type { Event } from '../types/event';

export default function AdminEventForm() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEditing = !!id;

  const [loading, setLoading] = useState(isEditing);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const [form, setForm] = useState<Partial<Event>>({
    title: '',
    description: '',
    short_description: '',
    banner: '',
    category: 'Masterclass',
    speaker_name: '',
    speaker_designation: '',
    speaker_image: '',
    event_date: '',
    start_time: '10:00',
    end_time: '11:00',
    duration: 60,
    mode: 'online',
    meeting_url: '',
    location: '',
    price: 0,
    status: 'draft',
  });

  useEffect(() => {
    if (isEditing && id) {
      API.get<Event>(`/admin/events/${id}`)
        .then((response) => {
          setForm(response.data);
        })
        .catch(() => {
          setError('Failed to load event');
        })
        .finally(() => setLoading(false));
    }
  }, [id, isEditing]);

  const handleChange = (
    e: React.ChangeEvent<
      HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
    >
  ) => {
    const { name, value, type } = e.target;
    setForm({
      ...form,
      [name]:
        type === 'number' ? (value === '' ? '' : parseFloat(value)) : value,
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');

    try {
      // Validate required fields
      if (
        !form.title ||
        !form.description ||
        !form.speaker_name ||
        !form.speaker_designation ||
        !form.event_date ||
        !form.start_time ||
        !form.end_time
      ) {
        setError('Please fill in all required fields');
        setSubmitting(false);
        return;
      }

      const payload = {
        ...form,
        registration_limit:
          form.registration_limit === undefined ? null : form.registration_limit,
      };

      if (isEditing && id) {
        await API.put(`/admin/events/${id}`, payload);
      } else {
        await API.post('/admin/events', payload);
      }

      navigate('/admin/events');
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(
        response.response?.data?.message || 'Failed to save event'
      );
    } finally {
      setSubmitting(false);
    }
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

  return (
    <div className="min-h-screen bg-slate-50">
      <Navbar />

      <div className="max-w-4xl mx-auto px-6 py-12">
        <div className="mb-8">
          <Link
            to="/admin/events"
            className="text-blue-600 hover:text-blue-700 font-semibold mb-4 inline-block"
          >
            ← Back to Events
          </Link>
          <h1 className="text-4xl font-bold text-slate-900">
            {isEditing ? 'Edit Event' : 'Create Event'}
          </h1>
        </div>

        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
            {error}
          </div>
        )}

        <form
          onSubmit={handleSubmit}
          className="bg-white rounded-lg border border-slate-200 shadow-sm p-8 space-y-6"
        >
          {/* Basic Information */}
          <div>
            <h2 className="text-xl font-bold text-slate-900 mb-4">
              Basic Information
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Title *
                </label>
                <input
                  type="text"
                  name="title"
                  value={form.title || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Category
                </label>
                <input
                  type="text"
                  name="category"
                  value={form.category || ''}
                  onChange={handleChange}
                  placeholder="e.g., Masterclass, Workshop"
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                />
              </div>
            </div>

            <div className="mt-4">
              <label className="block text-sm font-semibold text-slate-900 mb-2">
                Short Description
              </label>
              <textarea
                name="short_description"
                value={form.short_description || ''}
                onChange={handleChange}
                rows={2}
                className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
              />
            </div>

            <div className="mt-4">
              <label className="block text-sm font-semibold text-slate-900 mb-2">
                Description *
              </label>
              <textarea
                name="description"
                value={form.description || ''}
                onChange={handleChange}
                rows={4}
                required
                className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
              />
            </div>
            {/* Event Banner */}
            <div className="mt-4">
              <ImageUploadField
                label="Event Masterclass Banner / Thumbnail"
                value={form.banner}
                onChange={(url) => setForm({ ...form, banner: url })}
                folder="events"
                aspectRatio="banner"
                helpText="Wide banner graphic for the event details and masterclass card."
              />
            </div>
          </div>

          {/* Speaker Information */}
          <div>
            <h2 className="text-xl font-bold text-slate-900 mb-4">
              Speaker Information
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Speaker Name *
                </label>
                <input
                  type="text"
                  name="speaker_name"
                  value={form.speaker_name || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Speaker Designation *
                </label>
                <input
                  type="text"
                  name="speaker_designation"
                  value={form.speaker_designation || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>
            </div>

            <div className="mt-4">
              <ImageUploadField
                label="Speaker Profile Photo"
                value={form.speaker_image}
                onChange={(url) => setForm({ ...form, speaker_image: url })}
                folder="events"
                aspectRatio="avatar"
                helpText="Upload a professional photo for the masterclass speaker."
              />
            </div>
          </div>

          {/* Event Details */}
          <div>
            <h2 className="text-xl font-bold text-slate-900 mb-4">
              Event Details
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Event Date *
                </label>
                <input
                  type="datetime-local"
                  name="event_date"
                  value={form.event_date || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Duration (minutes) *
                </label>
                <input
                  type="number"
                  name="duration"
                  value={form.duration || ''}
                  onChange={handleChange}
                  min="1"
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Start Time *
                </label>
                <input
                  type="time"
                  name="start_time"
                  value={form.start_time || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  End Time *
                </label>
                <input
                  type="time"
                  name="end_time"
                  value={form.end_time || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                  required
                />
              </div>
            </div>
          </div>

          {/* Location & Mode */}
          <div>
            <h2 className="text-xl font-bold text-slate-900 mb-4">
              Location & Mode
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Mode
                </label>
                <select
                  name="mode"
                  value={form.mode || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                >
                  <option value="online">Online</option>
                  <option value="offline">Offline</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Meeting URL (for online events)
                </label>
                <input
                  type="url"
                  name="meeting_url"
                  value={form.meeting_url || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                />
              </div>

              <div className="sm:col-span-2">
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Location (for offline events)
                </label>
                <input
                  type="text"
                  name="location"
                  value={form.location || ''}
                  onChange={handleChange}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                />
              </div>
            </div>
          </div>

          {/* Pricing & Registration */}
          <div>
            <h2 className="text-xl font-bold text-slate-900 mb-4">
              Pricing & Registration
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Price (₹)
                </label>
                <input
                  type="number"
                  name="price"
                  value={form.price || ''}
                  onChange={handleChange}
                  min="0"
                  step="0.01"
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-slate-900 mb-2">
                  Registration Limit (leave empty for unlimited)
                </label>
                <input
                  type="number"
                  name="registration_limit"
                  value={form.registration_limit || ''}
                  onChange={handleChange}
                  min="1"
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                />
              </div>
            </div>
          </div>

          {/* Media */}
          <div>
            <h2 className="text-xl font-bold text-slate-900 mb-4">Media</h2>
            <div>
              <label className="block text-sm font-semibold text-slate-900 mb-2">
                Banner Image URL
              </label>
              <input
                type="url"
                name="banner"
                value={form.banner || ''}
                onChange={handleChange}
                className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
              />
            </div>
          </div>

          {/* Status */}
          <div>
            <h2 className="text-xl font-bold text-slate-900 mb-4">Status</h2>
            <select
              name="status"
              value={form.status || ''}
              onChange={handleChange}
              className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
            >
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>

          {/* Form Actions */}
          <div className="flex gap-4 pt-6 border-t border-slate-200">
            <button
              type="submit"
              disabled={submitting}
              className="flex-1 py-2 px-4 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {submitting ? 'Saving...' : isEditing ? 'Update Event' : 'Create Event'}
            </button>
            <Link
              to="/admin/events"
              className="flex-1 py-2 px-4 border border-slate-300 text-slate-900 rounded-lg font-semibold hover:bg-slate-50 transition text-center"
            >
              Cancel
            </Link>
          </div>
        </form>
      </div>
    </div>
  );
}
