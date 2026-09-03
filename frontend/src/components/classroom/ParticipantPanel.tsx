import type { ClassroomParticipant } from '../../types/classroom';

interface ParticipantPanelProps {
  participants: ClassroomParticipant[];
  currentUserId?: number;
  isHost: boolean;
  isAdmin: boolean;
  onToggleMic: (targetUserId: number, currentState: boolean) => void;
  onToggleCamera: (targetUserId: number, currentState: boolean) => void;
  onTransferHost?: (targetUserId: number) => void;
  onRemoveParticipant?: (targetUserId: number) => void;
  actionLoadingUserId?: number | null;
}

export default function ParticipantPanel({
  participants,
  currentUserId,
  isHost,
  isAdmin,
  onToggleMic,
  onToggleCamera,
  onTransferHost,
  onRemoveParticipant,
  actionLoadingUserId,
}: ParticipantPanelProps) {
  return (
    <div className="bg-slate-900 border border-slate-800 rounded-3xl p-5 space-y-4 shadow-xl flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h3 className="text-xs font-black text-white uppercase tracking-wider flex items-center gap-2">
          <span>👥</span>
          <span>Classroom Participants</span>
        </h3>
        <span className="px-2.5 py-0.5 rounded-full bg-slate-800 text-slate-300 font-bold text-xs border border-slate-700">
          {participants.length}
        </span>
      </div>

      {/* Participants List */}
      <div className="space-y-2.5 overflow-y-auto flex-grow max-h-[460px] pr-1">
        {participants.map((p) => {
          const isMe = p.user_id === currentUserId;
          const isParticipantHost = p.is_host_active || p.role === 'host';
          const isTutor = p.user?.role === 'tutor';
          const isLoading = actionLoadingUserId === p.user_id;

          return (
            <div
              key={p.id}
              className={`p-3 rounded-2xl border transition-all text-xs space-y-2 ${
                p.is_hand_raised
                  ? 'bg-amber-950/30 border-amber-500/50 shadow-md ring-1 ring-amber-500/30'
                  : isParticipantHost
                  ? 'bg-slate-950/80 border-amber-500/30'
                  : 'bg-slate-950/50 border-slate-800'
              }`}
            >
              {/* Top Row: User Avatar, Name, Role Badges */}
              <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2.5 min-w-0">
                  <div
                    className={`w-7 h-7 rounded-xl flex items-center justify-center font-black text-xs shrink-0 ${
                      isParticipantHost
                        ? 'bg-amber-500 text-slate-950'
                        : 'bg-blue-600 text-white'
                    }`}
                  >
                    {p.user?.name?.charAt(0).toUpperCase() || 'U'}
                  </div>

                  <div className="min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="font-bold text-white truncate block text-[11px]">
                        {p.user?.name || `User #${p.user_id}`}
                      </span>
                      {isMe && (
                        <span className="text-[10px] text-blue-400 font-extrabold">(You)</span>
                      )}
                    </div>
                    <span className="text-[10px] text-slate-400 block truncate">
                      {isParticipantHost ? 'Active Host' : isTutor ? 'Assigned Faculty' : 'Student'}
                    </span>
                  </div>
                </div>

                {/* State Badges */}
                <div className="flex items-center gap-1 shrink-0">
                  {p.is_hand_raised && (
                    <span className="px-1.5 py-0.5 rounded-lg bg-amber-500 text-slate-950 font-black text-[9px] flex items-center gap-1 animate-pulse">
                      <span>✋</span>
                      <span className="hidden sm:inline">Hand Raised</span>
                    </span>
                  )}
                  <span
                    className={`px-1.5 py-0.5 rounded-lg text-[10px] border ${
                      p.is_mic_allowed
                        ? 'bg-emerald-950/70 border-emerald-500/40 text-emerald-300'
                        : 'bg-red-950/70 border-red-500/40 text-red-300'
                    }`}
                    title={p.is_mic_allowed ? 'Microphone Permitted' : 'Microphone Restricted'}
                  >
                    {p.is_mic_allowed ? '🎙️' : '🔇'}
                  </span>
                  <span
                    className={`px-1.5 py-0.5 rounded-lg text-[10px] border ${
                      p.is_camera_allowed
                        ? 'bg-emerald-950/70 border-emerald-500/40 text-emerald-300'
                        : 'bg-red-950/70 border-red-500/40 text-red-300'
                    }`}
                    title={p.is_camera_allowed ? 'Camera Permitted' : 'Camera Restricted'}
                  >
                    {p.is_camera_allowed ? '📹' : '🚫'}
                  </span>
                </div>
              </div>

              {/* Bottom Row: Moderation Controls (Visible to Host for non-host participants) */}
              {isHost && !isParticipantHost && (
                <div className="pt-2 border-t border-slate-800/80 flex flex-wrap items-center justify-between gap-1.5">
                  <div className="flex items-center gap-1.5">
                    {/* Toggle Mic Button */}
                    <button
                      type="button"
                      onClick={() => onToggleMic(p.user_id, p.is_mic_allowed)}
                      disabled={isLoading}
                      className={`px-2 py-1 rounded-lg text-[10px] font-bold transition flex items-center gap-1 ${
                        p.is_mic_allowed
                          ? 'bg-red-950/80 hover:bg-red-900 border border-red-700 text-red-300'
                          : 'bg-emerald-950/80 hover:bg-emerald-900 border border-emerald-600 text-emerald-300'
                      }`}
                    >
                      <span>{p.is_mic_allowed ? '🔇 Mute' : '🎙️ Allow Mic'}</span>
                    </button>

                    {/* Toggle Camera Button */}
                    <button
                      type="button"
                      onClick={() => onToggleCamera(p.user_id, p.is_camera_allowed)}
                      disabled={isLoading}
                      className={`px-2 py-1 rounded-lg text-[10px] font-bold transition flex items-center gap-1 ${
                        p.is_camera_allowed
                          ? 'bg-red-950/80 hover:bg-red-900 border border-red-700 text-red-300'
                          : 'bg-emerald-950/80 hover:bg-emerald-900 border border-emerald-600 text-emerald-300'
                      }`}
                    >
                      <span>{p.is_camera_allowed ? '🚫 Stop Cam' : '📹 Allow Cam'}</span>
                    </button>

                    {/* Remove Participant Button */}
                    {onRemoveParticipant && (
                      <button
                        type="button"
                        onClick={() => onRemoveParticipant(p.user_id)}
                        disabled={isLoading}
                        title="Remove participant from classroom"
                        className="px-2 py-1 rounded-lg bg-slate-800 hover:bg-red-950 hover:text-red-300 border border-slate-700 text-slate-400 text-[10px] font-bold transition"
                      >
                        ✕ Remove
                      </button>
                    )}
                  </div>

                  {/* Admin Transfer Host to Tutor */}
                  {isAdmin && isTutor && onTransferHost && (
                    <button
                      type="button"
                      onClick={() => onTransferHost(p.user_id)}
                      disabled={isLoading}
                      className="px-2 py-1 rounded-lg bg-amber-500/20 hover:bg-amber-500/30 border border-amber-500/50 text-amber-300 text-[10px] font-black transition flex items-center gap-1"
                    >
                      <span>👑 Make Host</span>
                    </button>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
