interface RaiseHandButtonProps {
  isHandRaised: boolean;
  onToggle: () => void;
  loading?: boolean;
}

export default function RaiseHandButton({
  isHandRaised,
  onToggle,
  loading = false,
}: RaiseHandButtonProps) {
  return (
    <button
      type="button"
      onClick={onToggle}
      disabled={loading}
      className={`px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 shadow-sm ${
        isHandRaised
          ? 'bg-amber-500 hover:bg-amber-600 text-slate-950 ring-2 ring-amber-400/50 animate-pulse'
          : 'bg-slate-800 hover:bg-slate-700 text-amber-300 border border-amber-500/30'
      }`}
    >
      <span className="text-sm">✋</span>
      <span>
        {loading
          ? 'Updating...'
          : isHandRaised
          ? 'Hand Raised (Lower)'
          : 'Raise Hand'}
      </span>
    </button>
  );
}
