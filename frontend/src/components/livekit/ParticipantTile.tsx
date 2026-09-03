import { useEffect, useRef } from 'react';
import type { Participant, TrackPublication } from 'livekit-client';

interface ParticipantTileProps {
  participant: Participant;
  isLocal?: boolean;
  isHost?: boolean;
  videoTrack?: TrackPublication;
  audioTrack?: TrackPublication;
  isSpeaking?: boolean;
}

export default function ParticipantTile({
  participant,
  isLocal = false,
  isHost = false,
  videoTrack,
  audioTrack,
  isSpeaking = false,
}: ParticipantTileProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const audioRef = useRef<HTMLAudioElement>(null);

  // Attach / detach video track
  useEffect(() => {
    const videoEl = videoRef.current;
    if (!videoEl) return;

    if (videoTrack?.track && !videoTrack.isMuted) {
      videoTrack.track.attach(videoEl);
    }

    return () => {
      if (videoTrack?.track && videoEl) {
        videoTrack.track.detach(videoEl);
      }
    };
  }, [videoTrack, videoTrack?.track, videoTrack?.isMuted]);

  // Attach remote audio track
  useEffect(() => {
    const audioEl = audioRef.current;
    if (!audioEl || isLocal) return;

    if (audioTrack?.track && !audioTrack.isMuted) {
      audioTrack.track.attach(audioEl);
    }

    return () => {
      if (audioTrack?.track && audioEl) {
        audioTrack.track.detach(audioEl);
      }
    };
  }, [audioTrack, audioTrack?.track, audioTrack?.isMuted, isLocal]);

  const hasVideo = Boolean(videoTrack?.track && !videoTrack.isMuted);
  const isAudioMuted = !audioTrack || audioTrack.isMuted;
  const displayName = participant.name || participant.identity || 'Participant';

  return (
    <div
      className={`relative bg-slate-900 rounded-2xl overflow-hidden aspect-video flex items-center justify-center border transition-all duration-200 shadow-lg ${
        isSpeaking
          ? 'border-emerald-500 shadow-emerald-500/20 ring-2 ring-emerald-500/30'
          : 'border-slate-800 hover:border-slate-700'
      }`}
    >
      {/* Video Element */}
      <video
        ref={videoRef}
        autoPlay
        playsInline
        muted={isLocal}
        className={`w-full h-full object-cover ${hasVideo ? 'block' : 'hidden'} ${
          isLocal ? 'scale-x-[-1]' : ''
        }`}
      />

      {/* Hidden Audio Element for Remote Participants */}
      {!isLocal && <audio ref={audioRef} autoPlay />}

      {/* Fallback Avatar Placeholder when Video is Off */}
      {!hasVideo && (
        <div className="flex flex-col items-center justify-center gap-2 text-center p-4">
          <div className="w-16 h-16 rounded-2xl bg-slate-800 border border-slate-700 flex items-center justify-center text-xl font-bold text-white shadow-inner">
            {displayName.charAt(0).toUpperCase()}
          </div>
          <span className="text-xs font-semibold text-slate-300 line-clamp-1">{displayName}</span>
        </div>
      )}

      {/* Bottom Identity & Status Pill */}
      <div className="absolute bottom-2.5 left-2.5 right-2.5 flex items-center justify-between pointer-events-none">
        <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[11px] font-semibold text-white max-w-[70%] truncate">
          <span className="truncate">{displayName}</span>
          {isLocal && <span className="text-blue-400 font-bold">(You)</span>}
          {isHost && (
            <span className="px-1.5 py-0.2 rounded bg-amber-500 text-slate-950 font-black text-[9px]">
              HOST
            </span>
          )}
        </div>

        {/* Audio Muted Indicator */}
        <div
          className={`px-2 py-1 rounded-xl border text-[11px] backdrop-blur-md flex items-center gap-1 ${
            isAudioMuted
              ? 'bg-red-950/80 border-red-500/50 text-red-300'
              : 'bg-slate-950/80 border-slate-800 text-emerald-400'
          }`}
        >
          <span>{isAudioMuted ? '🔇' : '🎙️'}</span>
        </div>
      </div>
    </div>
  );
}
