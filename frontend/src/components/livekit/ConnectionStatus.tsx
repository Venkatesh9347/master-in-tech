import { ConnectionState } from 'livekit-client';

interface ConnectionStatusProps {
  state: ConnectionState;
  roomName?: string;
  isMockMode?: boolean;
}

export default function ConnectionStatus({
  state,
  roomName,
  isMockMode = false,
}: ConnectionStatusProps) {
  const getStatusConfig = () => {
    if (isMockMode) {
      return {
        label: 'Ready (Local SFU Target)',
        color: 'bg-blue-400',
        badge: 'bg-blue-950/60 border-blue-600/50 text-blue-300',
      };
    }

    switch (state) {
      case ConnectionState.Connected:
        return {
          label: 'Live WebRTC Connected',
          color: 'bg-emerald-400',
          badge: 'bg-emerald-950/60 border-emerald-600/50 text-emerald-300',
        };
      case ConnectionState.Connecting:
        return {
          label: 'Connecting to SFU...',
          color: 'bg-amber-400',
          badge: 'bg-amber-950/60 border-amber-600/50 text-amber-300',
        };
      case ConnectionState.Reconnecting:
        return {
          label: 'Reconnecting...',
          color: 'bg-amber-400 animate-pulse',
          badge: 'bg-amber-950/60 border-amber-600/50 text-amber-300',
        };
      case ConnectionState.Disconnected:
      default:
        return {
          label: 'Disconnected',
          color: 'bg-slate-500',
          badge: 'bg-slate-900 border-slate-700 text-slate-400',
        };
    }
  };

  const config = getStatusConfig();

  return (
    <div className="flex items-center gap-2 text-xs">
      <div
        className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-xl font-semibold border ${config.badge} transition shadow-xs`}
      >
        <span className={`w-2 h-2 rounded-full ${config.color} ${state === ConnectionState.Connected ? 'animate-pulse' : ''}`} />
        <span>{config.label}</span>
      </div>

      {roomName && (
        <span className="hidden sm:inline-block px-2.5 py-1 rounded-xl bg-slate-900 border border-slate-800 text-[11px] font-mono text-slate-400">
          {roomName}
        </span>
      )}
    </div>
  );
}
