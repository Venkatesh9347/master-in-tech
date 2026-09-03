import { useEffect, useState, useRef, useCallback } from 'react';
import {
  Room,
  RoomEvent,
  ConnectionState,
  Track,
  type RemoteParticipant,
  type LocalParticipant,
  type TrackPublication,
} from 'livekit-client';
import ConnectionStatus from './ConnectionStatus';
import ParticipantGrid from './ParticipantGrid';
import RoomControls from './RoomControls';

interface LiveClassRoomProps {
  token: string;
  wsUrl: string;
  roomName: string;
  isHost?: boolean;
  canPublish?: boolean;
  onLeave: () => void;
}

export default function LiveClassRoom({
  token,
  wsUrl,
  roomName,
  isHost = false,
  canPublish = false,
  onLeave,
}: LiveClassRoomProps) {
  const roomRef = useRef<Room | null>(null);

  const [connectionState, setConnectionState] = useState<ConnectionState>(
    ConnectionState.Disconnected
  );
  const [localParticipant, setLocalParticipant] = useState<LocalParticipant | null>(null);
  const [remoteParticipants, setRemoteParticipants] = useState<RemoteParticipant[]>([]);
  const [activeSpeakers, setActiveSpeakers] = useState<string[]>([]);
  const [screenShareTrack, setScreenShareTrack] = useState<TrackPublication | null>(null);

  const [isMicEnabled, setIsMicEnabled] = useState(false);
  const [isCameraEnabled, setIsCameraEnabled] = useState(false);
  const [isScreenSharing, setIsScreenSharing] = useState(false);
  const [deviceError, setDeviceError] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);

  // Phase 3 UI Additions: View Modes & Fullscreen
  const [viewMode, setViewMode] = useState<'grid' | 'speaker'>('grid');
  const [isFullscreen, setIsFullscreen] = useState(false);

  // Sync participants list from room instance
  const updateParticipants = useCallback((room: Room) => {
    setLocalParticipant(room.localParticipant);
    setRemoteParticipants(Array.from(room.remoteParticipants.values()));
  }, []);

  // When canPublish is revoked by host (e.g. muted), turn off local mic immediately
  useEffect(() => {
    if (!canPublish && roomRef.current) {
      if (isMicEnabled) {
        roomRef.current.localParticipant.setMicrophoneEnabled(false).catch(() => {});
        setIsMicEnabled(false);
      }
      if (isCameraEnabled) {
        roomRef.current.localParticipant.setCameraEnabled(false).catch(() => {});
        setIsCameraEnabled(false);
      }
    }
  }, [canPublish, isMicEnabled, isCameraEnabled]);

  // Connect to LiveKit Room
  useEffect(() => {
    if (!token || !wsUrl) return;

    let isMounted = true;
    const room = new Room({
      adaptiveStream: true,
      dynacast: true,
      stopLocalTrackOnUnpublish: true,
    });
    roomRef.current = room;

    const setupEventListeners = () => {
      room.on(RoomEvent.Connected, () => {
        if (!isMounted) return;
        setConnectionState(ConnectionState.Connected);
        setServerError(null);
        updateParticipants(room);
      });

      room.on(RoomEvent.Disconnected, () => {
        if (!isMounted) return;
        setConnectionState(ConnectionState.Disconnected);
        updateParticipants(room);
      });

      room.on(RoomEvent.Reconnecting, () => {
        if (!isMounted) return;
        setConnectionState(ConnectionState.Reconnecting);
      });

      room.on(RoomEvent.Reconnected, () => {
        if (!isMounted) return;
        setConnectionState(ConnectionState.Connected);
        updateParticipants(room);
      });

      room.on(RoomEvent.ParticipantConnected, () => {
        if (!isMounted) return;
        updateParticipants(room);
      });

      room.on(RoomEvent.ParticipantDisconnected, () => {
        if (!isMounted) return;
        updateParticipants(room);
      });

      room.on(RoomEvent.TrackSubscribed, () => {
        if (!isMounted) return;
        updateParticipants(room);
      });

      room.on(RoomEvent.TrackUnsubscribed, () => {
        if (!isMounted) return;
        updateParticipants(room);
      });

      room.on(RoomEvent.TrackMuted, () => {
        if (!isMounted) return;
        updateParticipants(room);
      });

      room.on(RoomEvent.TrackUnmuted, () => {
        if (!isMounted) return;
        updateParticipants(room);
      });

      room.on(RoomEvent.ActiveSpeakersChanged, (speakers) => {
        if (!isMounted) return;
        setActiveSpeakers(speakers.map((s) => s.identity));
      });

      room.on(RoomEvent.LocalTrackPublished, (pub) => {
        if (!isMounted) return;
        if (pub.source === Track.Source.Microphone) setIsMicEnabled(true);
        if (pub.source === Track.Source.Camera) setIsCameraEnabled(true);
        if (pub.source === Track.Source.ScreenShare) {
          setIsScreenSharing(true);
          setScreenShareTrack(pub);
        }
        updateParticipants(room);
      });

      room.on(RoomEvent.LocalTrackUnpublished, (pub) => {
        if (!isMounted) return;
        if (pub.source === Track.Source.Microphone) setIsMicEnabled(false);
        if (pub.source === Track.Source.Camera) setIsCameraEnabled(false);
        if (pub.source === Track.Source.ScreenShare) {
          setIsScreenSharing(false);
          setScreenShareTrack(null);
        }
        updateParticipants(room);
      });
    };

    setupEventListeners();
    setConnectionState(ConnectionState.Connecting);

    room
      .connect(wsUrl, token)
      .then(async () => {
        if (!isMounted) return;
        if (canPublish && isHost) {
          try {
            await room.localParticipant.setMicrophoneEnabled(true);
            await room.localParticipant.setCameraEnabled(true);
          } catch (err: unknown) {
            const e = err as Error;
            setDeviceError(`Media device notice: ${e.message}`);
          }
        }
      })
      .catch((err: unknown) => {
        if (!isMounted) return;
        const e = err as Error;
        setConnectionState(ConnectionState.Disconnected);
        setServerError(
          `Unable to establish WebRTC connection to LiveKit server at ${wsUrl}. (${e.message})`
        );
      });

    return () => {
      isMounted = false;
      room.disconnect();
      roomRef.current = null;
    };
  }, [token, wsUrl, isHost, canPublish, updateParticipants]);

  // Toggle Microphone
  const handleToggleMic = async () => {
    const room = roomRef.current;
    if (!room || !canPublish) return;

    try {
      setDeviceError(null);
      const newState = !isMicEnabled;
      await room.localParticipant.setMicrophoneEnabled(newState);
      setIsMicEnabled(newState);
      updateParticipants(room);
    } catch (err: unknown) {
      const e = err as Error;
      setDeviceError(`Microphone access error: ${e.message}`);
    }
  };

  // Toggle Camera
  const handleToggleCamera = async () => {
    const room = roomRef.current;
    if (!room || !canPublish) return;

    try {
      setDeviceError(null);
      const newState = !isCameraEnabled;
      await room.localParticipant.setCameraEnabled(newState);
      setIsCameraEnabled(newState);
      updateParticipants(room);
    } catch (err: unknown) {
      const e = err as Error;
      setDeviceError(`Camera access error: ${e.message}`);
    }
  };

  // Toggle Screen Sharing
  const handleToggleScreenShare = async () => {
    const room = roomRef.current;
    if (!room) return;

    try {
      setDeviceError(null);
      const newState = !isScreenSharing;
      await room.localParticipant.setScreenShareEnabled(newState);
      setIsScreenSharing(newState);
      updateParticipants(room);
    } catch (err: unknown) {
      const e = err as Error;
      setDeviceError(`Screen sharing error: ${e.message}`);
    }
  };

  // Toggle Fullscreen
  const handleToggleFullscreen = () => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().then(() => setIsFullscreen(true)).catch(() => {});
    } else {
      document.exitFullscreen().then(() => setIsFullscreen(false)).catch(() => {});
    }
  };

  return (
    <div className="flex flex-col flex-grow gap-4 h-full">
      {/* Top Connection Bar with View Mode & Fullscreen Controls */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-slate-900/60 p-3 rounded-2xl border border-slate-800 backdrop-blur-md">
        <ConnectionStatus state={connectionState} roomName={roomName} />

        <div className="flex items-center gap-2 text-xs">
          {/* View Mode Toggle (Grid vs Speaker) */}
          <div className="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800">
            <button
              type="button"
              onClick={() => setViewMode('grid')}
              className={`px-2.5 py-1 rounded-lg font-bold text-[11px] transition ${
                viewMode === 'grid'
                  ? 'bg-blue-600 text-white'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
            >
              ⊞ Grid
            </button>
            <button
              type="button"
              onClick={() => setViewMode('speaker')}
              className={`px-2.5 py-1 rounded-lg font-bold text-[11px] transition ${
                viewMode === 'speaker'
                  ? 'bg-blue-600 text-white'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
            >
              👤 Speaker
            </button>
          </div>

          {/* Fullscreen Toggle */}
          <button
            type="button"
            onClick={handleToggleFullscreen}
            title={isFullscreen ? 'Exit Fullscreen' : 'Enter Fullscreen'}
            className="px-2.5 py-1.5 rounded-xl bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 font-bold text-xs transition"
          >
            {isFullscreen ? '↙ Exit Full' : '↗ Fullscreen'}
          </button>

          <span className="text-slate-400 font-semibold hidden sm:inline">
            👥 {remoteParticipants.length + (localParticipant ? 1 : 0)} Active
          </span>
        </div>
      </div>

      {/* Server / Device Warning Alert */}
      {serverError && (
        <div className="p-3.5 rounded-2xl bg-amber-950/70 border border-amber-600/50 text-amber-300 text-xs space-y-1">
          <div className="flex items-center gap-2 font-bold">
            <span>⚠️</span>
            <span>LiveKit Connection Notice</span>
          </div>
          <p className="text-[11px] text-amber-400/90 leading-relaxed">{serverError}</p>
        </div>
      )}

      {deviceError && (
        <div className="p-3 rounded-xl bg-red-950/70 border border-red-500/40 text-red-300 text-xs flex items-center justify-between">
          <span>{deviceError}</span>
          <button
            type="button"
            onClick={() => setDeviceError(null)}
            className="text-slate-400 hover:text-white text-xs font-bold"
          >
            ✕
          </button>
        </div>
      )}

      {/* Main Video & Audio Stage */}
      <div className="flex-grow min-h-[380px] bg-slate-950/60 rounded-3xl border border-slate-800/80 p-4 flex flex-col justify-center overflow-hidden">
        <ParticipantGrid
          localParticipant={localParticipant}
          remoteParticipants={remoteParticipants}
          isHost={isHost}
          activeSpeakers={activeSpeakers}
          screenShareTrack={screenShareTrack}
        />
      </div>

      {/* Bottom Room Control Bar */}
      <RoomControls
        isMicEnabled={isMicEnabled}
        isCameraEnabled={isCameraEnabled}
        isScreenSharing={isScreenSharing}
        isHost={isHost}
        canPublish={canPublish}
        onToggleMic={handleToggleMic}
        onToggleCamera={handleToggleCamera}
        onToggleScreenShare={handleToggleScreenShare}
        onLeave={onLeave}
        connecting={connectionState === ConnectionState.Connecting}
      />
    </div>
  );
}
