interface EventBadgeProps {
  category: string;
}

const categoryColors: Record<string, { bg: string; text: string }> = {
  masterclass: { bg: 'bg-purple-100', text: 'text-purple-700' },
  workshop: { bg: 'bg-blue-100', text: 'text-blue-700' },
  webinar: { bg: 'bg-green-100', text: 'text-green-700' },
  conference: { bg: 'bg-orange-100', text: 'text-orange-700' },
  training: { bg: 'bg-pink-100', text: 'text-pink-700' },
};

export default function EventBadge({ category }: EventBadgeProps) {
  const colors = categoryColors[category.toLowerCase()] || {
    bg: 'bg-slate-100',
    text: 'text-slate-700',
  };

  return (
    <span
      className={`inline-block px-3 py-1 rounded-full text-xs font-semibold ${colors.bg} ${colors.text} uppercase tracking-wide`}
    >
      {category}
    </span>
  );
}
