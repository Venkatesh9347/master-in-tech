interface RoomControlsProps {
  isMicEnabled: boolean;
  isCameraEnabled: boolean;
  isScreenSharing: boolean;
  isHost: boolean;
  canPublish: boolean;
  onToggleMic: () => void;
  onToggleCamera: () => void;
  onToggleScreenShare: () => void;
  onLeave: () => void;
  connecting?: boolean;
}

export default function RoomControls({
  isMicEnabled,
  isCameraEnabled,
  isScreenSharing,
  isHost,
  canPublish,
  onToggleMic,
  onToggleCamera,
  onToggleScreenShare,
  onLeave,
  connecting = false,
}: RoomControlsProps) {
  return (
    <div className="bg-slate-950/90 backdrop-blur-md px-4 py-3 rounded-2xl border border-slate-800 flex flex-wrap items-center justify-between gap-3 shadow-xl">
      {/* Left Media Toggles */}
      <div className="flex items-center gap-2 sm:gap-3">
        {/* Microphone Button */}
        <button
          type="button"
          onClick={onToggleMic}
          disabled={!canPublish || connecting}
          title={
            !canPublish
              ? 'Microphone is restricted for students by classroom policy'
              : isMicEnabled
              ? 'Mute Microphone'
              : 'Unmute Microphone'
          }
          className={`px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs ${
            !canPublish
              ? 'bg-slate-900 text-slate-500 border border-slate-800 cursor-not-allowed opacity-60'
              : isMicEnabled
              ? 'bg-slate-800 hover:bg-slate-700 text-white border border-slate-700'
              : 'bg-red-600 hover:bg-red-700 text-white border border-red-500 shadow-red-500/20'
          }`}
        >
          <span>{isMicEnabled && canPublish ? '🎙️' : '🔇'}</span>
          <span className="hidden sm:inline">
            {!canPublish ? 'Mic Locked' : isMicEnabled ? 'Mute' : 'Unmute'}
          </span>
        </button>

        {/* Camera Button */}
        <button
          type="button"
          onClick={onToggleCamera}
          disabled={!canPublish || connecting}
          title={
            !canPublish
              ? 'Camera is restricted for students by classroom policy'
              : isCameraEnabled
              ? 'Turn Off Camera'
              : 'Turn On Camera'
          }
          className={`px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs ${
            !canPublish
              ? 'bg-slate-900 text-slate-500 border border-slate-800 cursor-not-allowed opacity-60'
              : isCameraEnabled
              ? 'bg-slate-800 hover:bg-slate-700 text-white border border-slate-700'
              : 'bg-red-600 hover:bg-red-700 text-white border border-red-500 shadow-red-500/20'
          }`}
        >
          <span>{isCameraEnabled && canPublish ? '📹' : '🚫'}</span>
          <span className="hidden sm:inline">
            {!canPublish ? 'Camera Locked' : isCameraEnabled ? 'Stop Video' : 'Start Video'}
          </span>
        </button>

        {/* Screen Share (Host / Permitted) */}
        {isHost && (
          <button
            type="button"
            onClick={onToggleScreenShare}
            disabled={connecting}
            title={isScreenSharing ? 'Stop Screen Share' : 'Share Screen'}
            className={`px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs ${
              isScreenSharing
                ? 'bg-blue-600 hover:bg-blue-700 text-white border border-blue-500'
                : 'bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700'
            }`}
          >
            <span>🖥️</span>
            <span className="hidden sm:inline">
              {isScreenSharing ? 'Stop Sharing' : 'Share Screen'}
            </span>
          </button>
        )}
      </div>

      {/* Right Leave Button */}
      <button
        type="button"
        onClick={onLeave}
        className="px-4 py-2 rounded-xl bg-red-950/70 hover:bg-red-900 border border-red-700 text-red-300 font-bold text-xs transition flex items-center gap-1.5 shadow-xs"
      >
        <span>🚪</span>
        <span>Leave Classroom</span>
      </button>
    </div>
  );
}
