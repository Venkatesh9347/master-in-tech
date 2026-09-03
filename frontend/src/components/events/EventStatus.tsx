import type { EventStatus } from '../../types/event';

interface EventStatusProps {
  status: EventStatus;
  eventDate?: string;
}

export default function EventStatus({ status, eventDate }: EventStatusProps) {
  const isCompleted =
    status === 'completed' || (eventDate && new Date(eventDate) < new Date());

  const statusConfig: Record<
    EventStatus,
    { badge: string; color: string; label: string }
  > = {
    draft: {
      badge: 'bg-slate-100 text-slate-700',
      color: 'text-slate-600',
      label: 'Draft',
    },
    published: {
      badge: 'bg-green-100 text-green-700',
      color: 'text-green-600',
      label: 'Published',
    },
    completed: {
      badge: 'bg-blue-100 text-blue-700',
      color: 'text-blue-600',
      label: 'Completed',
    },
    cancelled: {
      badge: 'bg-red-100 text-red-700',
      color: 'text-red-600',
      label: 'Cancelled',
    },
  };

  const displayStatus = isCompleted ? 'completed' : status;
  const config = statusConfig[displayStatus];

  return (
    <span className={`inline-block px-3 py-1 rounded-full text-xs font-semibold ${config.badge}`}>
      {config.label}
    </span>
  );
}
