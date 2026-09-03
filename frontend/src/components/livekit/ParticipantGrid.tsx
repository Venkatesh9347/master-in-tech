import type { LocalParticipant, RemoteParticipant, TrackPublication } from 'livekit-client';
import { Track } from 'livekit-client';
import ParticipantTile from './ParticipantTile';

interface ParticipantGridProps {
  localParticipant?: LocalParticipant | null;
  remoteParticipants: RemoteParticipant[];
  isHost?: boolean;
  activeSpeakers?: string[];
  screenShareTrack?: TrackPublication | null;
}

export default function ParticipantGrid({
  localParticipant,
  remoteParticipants,
  isHost = false,
  activeSpeakers = [],
  screenShareTrack,
}: ParticipantGridProps) {
  const allParticipantsCount = (localParticipant ? 1 : 0) + remoteParticipants.length;

  const getGridColsClass = () => {
    if (screenShareTrack) {
      return 'grid-cols-1 md:grid-cols-4';
    }
    if (allParticipantsCount <= 1) {
      return 'grid-cols-1 max-w-2xl mx-auto';
    }
    if (allParticipantsCount === 2) {
      return 'grid-cols-1 md:grid-cols-2';
    }
    if (allParticipantsCount <= 4) {
      return 'grid-cols-1 sm:grid-cols-2';
    }
    return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
  };

  // Helper to get tracks from publication map
  const getTracks = (participant: LocalParticipant | RemoteParticipant) => {
    let videoTrack: TrackPublication | undefined;
    let audioTrack: TrackPublication | undefined;

    participant.videoTrackPublications.forEach((pub) => {
      if (pub.source === Track.Source.Camera) {
        videoTrack = pub;
      }
    });

    participant.audioTrackPublications.forEach((pub) => {
      if (pub.source === Track.Source.Microphone) {
        audioTrack = pub;
      }
    });

    return { videoTrack, audioTrack };
  };

  return (
    <div className="w-full flex-grow flex flex-col justify-center">
      {screenShareTrack ? (
        /* Screen Share Expanded Layout */
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-4 w-full h-full">
          <div className="lg:col-span-3 bg-black rounded-2xl overflow-hidden border border-slate-800 relative aspect-video flex items-center justify-center">
            {screenShareTrack.track && (
              <video
                ref={(el) => {
                  if (el && screenShareTrack.track) {
                    screenShareTrack.track.attach(el);
                  }
                }}
                autoPlay
                playsInline
                className="w-full h-full object-contain"
              />
            )}
            <div className="absolute top-3 left-3 px-3 py-1 rounded-xl bg-slate-950/80 border border-slate-800 text-xs font-bold text-blue-400 flex items-center gap-1.5">
              <span>🖥️</span>
              <span>Screen Share</span>
            </div>
          </div>

          {/* Participant Column next to screen share */}
          <div className="flex flex-col gap-3 max-h-[500px] overflow-y-auto">
            {localParticipant && (
              <ParticipantTile
                participant={localParticipant}
                isLocal={true}
                isHost={isHost}
                videoTrack={getTracks(localParticipant).videoTrack}
                audioTrack={getTracks(localParticipant).audioTrack}
                isSpeaking={activeSpeakers.includes(localParticipant.identity)}
              />
            )}
            {remoteParticipants.map((rp) => (
              <ParticipantTile
                key={rp.identity}
                participant={rp}
                isLocal={false}
                videoTrack={getTracks(rp).videoTrack}
                audioTrack={getTracks(rp).audioTrack}
                isSpeaking={activeSpeakers.includes(rp.identity)}
              />
            ))}
          </div>
        </div>
      ) : (
        /* Standard Gallery Grid */
        <div className={`grid gap-4 w-full ${getGridColsClass()}`}>
          {/* Local Participant Tile */}
          {localParticipant && (
            <ParticipantTile
              participant={localParticipant}
              isLocal={true}
              isHost={isHost}
              videoTrack={getTracks(localParticipant).videoTrack}
              audioTrack={getTracks(localParticipant).audioTrack}
              isSpeaking={activeSpeakers.includes(localParticipant.identity)}
            />
          )}

          {/* Remote Participants */}
          {remoteParticipants.map((rp) => (
            <ParticipantTile
              key={rp.identity}
              participant={rp}
              isLocal={false}
              videoTrack={getTracks(rp).videoTrack}
              audioTrack={getTracks(rp).audioTrack}
              isSpeaking={activeSpeakers.includes(rp.identity)}
            />
          ))}

          {/* Empty room state when alone */}
          {remoteParticipants.length === 0 && (
            <div className="flex flex-col items-center justify-center p-8 text-center bg-slate-900/40 rounded-2xl border border-dashed border-slate-800 text-slate-500 text-xs">
              <span className="text-2xl mb-2">👥</span>
              <p className="font-semibold text-slate-400">You are the only one in this room.</p>
              <p className="text-[11px] text-slate-500 mt-0.5">
                Other participants will appear automatically when they join.
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
