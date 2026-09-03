import { Link } from 'react-router-dom';
import type { Event } from '../../types/event';
import EventBadge from './EventBadge';
import EventStatus from './EventStatus';

interface EventCardProps {
  event: Event;
  showRegisterButton?: boolean;
  onRegister?: (eventId: number) => void;
}

export default function EventCard({
  event,
  showRegisterButton = true,
  onRegister,
}: EventCardProps) {
  const eventDate = new Date(event.event_date);
  const formattedDate = eventDate.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });

  const isFull = event.registration_limit
    ? event.registered_count >= event.registration_limit
    : false;
  const isCompleted = event.status === 'completed' || eventDate < new Date();

  return (
    <Link
      to={`/events/${event.id}`}
      className="group block rounded-xl bg-white shadow-sm ring-1 ring-slate-200 hover:shadow-lg hover:ring-slate-300 transition overflow-hidden"
    >
      <div className="relative overflow-hidden h-48 bg-gradient-to-br from-blue-100 to-blue-50">
        {event.banner ? (
          <img
            src={event.banner}
            alt={event.title}
            className="w-full h-full object-cover group-hover:scale-105 transition"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center bg-blue-600">
            <span className="text-white text-sm">Event Banner</span>
          </div>
        )}
        <div className="absolute top-4 left-4">
          <EventBadge category={event.category} />
        </div>
      </div>

      <div className="p-5">
        <div className="flex items-center gap-2 mb-2">
          <span className="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded">
            {event.registered_count} Registered
          </span>
        </div>

        <h3 className="text-lg font-bold text-slate-900 mb-2 group-hover:text-blue-600 transition line-clamp-2">
          {event.title}
        </h3>

        <p className="text-sm text-slate-600 mb-4 line-clamp-2">
          {event.short_description || event.description}
        </p>

        <div className="mb-4 pb-4 border-b border-slate-100">
          <div className="text-sm font-semibold text-slate-900 mb-1">
            {event.speaker_name}
          </div>
          <div className="text-xs text-slate-500">{event.speaker_designation}</div>
        </div>

        <div className="space-y-2 mb-4">
          <div className="flex items-center gap-2 text-sm text-slate-600">
            <span className="font-medium">📅</span>
            <span>{formattedDate}</span>
          </div>
          <div className="flex items-center gap-2 text-sm text-slate-600">
            <span className="font-medium">⏱️</span>
            <span>
              {event.start_time} - {event.end_time}
            </span>
          </div>
          <div className="flex items-center gap-2 text-sm text-slate-600">
            <span className="font-medium">
              {event.mode === 'online' ? '💻' : '📍'}
            </span>
            <span className="capitalize">{event.mode}</span>
          </div>
        </div>

        <div className="flex items-center gap-2 mb-4 text-xs font-bold text-emerald-600">
          <span>● Free Community Workshop</span>
        </div>

        {showRegisterButton && (
          <button
            onClick={(e) => {
              e.preventDefault();
              if (onRegister) onRegister(event.id);
            }}
            disabled={isFull || isCompleted}
            className={`w-full py-2 px-3 rounded-lg font-semibold text-sm transition ${
              isFull
                ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                : isCompleted
                  ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                  : 'bg-blue-600 text-white hover:bg-blue-700'
            }`}
          >
            {isFull ? 'Full' : isCompleted ? 'Completed' : 'Register Now'}
          </button>
        )}

        {!showRegisterButton && (
          <div className="pt-4 border-t border-slate-100">
            <EventStatus status={event.status} eventDate={event.event_date} />
          </div>
        )}
      </div>
    </Link>
  );
}
