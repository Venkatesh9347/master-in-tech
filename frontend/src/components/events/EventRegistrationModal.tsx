import type { Event } from '../../types/event';

interface EventRegistrationModalProps {
  isOpen: boolean;
  event: Event | null;
  isLoading: boolean;
  error: string;
  onConfirm: () => void;
  onClose: () => void;
}

export default function EventRegistrationModal({
  isOpen,
  event,
  isLoading,
  error,
  onConfirm,
  onClose,
}: EventRegistrationModalProps) {
  if (!isOpen || !event) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4">
      <div className="bg-white rounded-xl max-w-md w-full shadow-xl">
        <div className="p-6">
          <h2 className="text-2xl font-bold text-slate-900 mb-4">Confirm Registration</h2>

          <div className="space-y-3 mb-6 pb-6 border-b border-slate-200">
            <div>
              <p className="text-sm text-slate-600">Event</p>
              <p className="font-semibold text-slate-900">{event.title}</p>
            </div>

            <div>
              <p className="text-sm text-slate-600">Speaker</p>
              <p className="font-semibold text-slate-900">{event.speaker_name}</p>
            </div>

            <div>
              <p className="text-sm text-slate-600">Date & Time</p>
              <p className="font-semibold text-slate-900">
                {new Date(event.event_date).toLocaleDateString('en-US', {
                  weekday: 'short',
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
                })}{' '}
                | {event.start_time} - {event.end_time}
              </p>
            </div>

            <div>
              <p className="text-sm text-slate-600">Mode</p>
              <p className="font-semibold text-slate-900 capitalize">{event.mode}</p>
            </div>

            <div>
              <p className="text-sm text-slate-600">Access</p>
              <p className="font-semibold text-emerald-600">Free Community Workshop</p>
            </div>
          </div>

          {error && (
            <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
              <p className="text-sm text-red-700">{error}</p>
            </div>
          )}

          <div className="flex gap-3">
            <button
              onClick={onClose}
              disabled={isLoading}
              className="flex-1 py-2 px-4 border border-slate-300 text-slate-900 rounded-lg font-semibold hover:bg-slate-50 transition disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              onClick={onConfirm}
              disabled={isLoading}
              className="flex-1 py-2 px-4 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isLoading ? 'Registering...' : 'Register'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
