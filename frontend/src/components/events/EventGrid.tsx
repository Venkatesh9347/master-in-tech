import type { Event } from '../../types/event';
import EventCard from './EventCard';

interface EventGridProps {
  events: Event[];
  showRegisterButton?: boolean;
  onRegister?: (eventId: number) => void;
  emptyMessage?: string;
}

export default function EventGrid({
  events,
  showRegisterButton = true,
  onRegister,
  emptyMessage = 'No events found',
}: EventGridProps) {
  if (events.length === 0) {
    return (
      <div className="text-center py-12">
        <p className="text-slate-600 text-lg">{emptyMessage}</p>
      </div>
    );
  }

  return (
    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {events.map((event) => (
        <EventCard
          key={event.id}
          event={event}
          showRegisterButton={showRegisterButton}
          onRegister={onRegister}
        />
      ))}
    </div>
  );
}
