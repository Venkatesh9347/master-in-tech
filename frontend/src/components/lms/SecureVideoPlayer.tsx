import { useEffect, useRef, useState, useCallback } from 'react';
import Hls from 'hls.js';
import API from '../../services/api';
import DynamicWatermark from './DynamicWatermark';
import type { Lesson } from '../../types/lms';
import type { VideoPlaybackAuthResponse, VideoPlaybackSessionData } from '../../types/video';

interface SecureVideoPlayerProps {
  lesson: Lesson;
  courseId?: number;
  onProgress?: (currentTime: number, duration: number) => void;
}

export default function SecureVideoPlayer({
  lesson,
  courseId,
  onProgress,
}: SecureVideoPlayerProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const hlsRef = useRef<Hls | null>(null);
  const lastReportedTime = useRef<number>(0);

  const [sessionData, setSessionData] = useState<VideoPlaybackSessionData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [tamperAlert, setTamperAlert] = useState(false);

  // Resolution selector state
  const [qualities, setQualities] = useState<{ id: number; height: number; name: string }[]>([]);
  const [currentQuality, setCurrentQuality] = useState<number>(-1); // -1 = Auto

  const fetchPlaybackAuthorization = useCallback(async () => {
    if (!lesson.id) return;

    setLoading(true);
    setError('');
    setTamperAlert(false);

    try {
      const cId = courseId || lesson.course_id;
      const res = await API.post<VideoPlaybackAuthResponse>(
        `/courses/${cId}/lessons/${lesson.id}/playback-auth`
      );
      setSessionData(res.data.session);
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(
        response.response?.data?.message ||
          'Unable to authorize secure video playback. Please ensure your enrollment is active.'
      );
    } finally {
      setLoading(false);
    }
  }, [lesson.id, lesson.course_id, courseId]);

  useEffect(() => {
    fetchPlaybackAuthorization();
  }, [fetchPlaybackAuthorization]);

  // Setup HLS.js adaptive player instance
  useEffect(() => {
    const video = videoRef.current;
    if (!video || !sessionData) return;

    // Destroy existing HLS instance
    if (hlsRef.current) {
      hlsRef.current.destroy();
      hlsRef.current = null;
    }

    const streamUrl = sessionData.playback_url;

    if (Hls.isSupported()) {
      const hls = new Hls({
        enableWorker: true,
        lowLatencyMode: false,
        backBufferLength: 90,
      });

      hlsRef.current = hls;
      hls.loadSource(streamUrl);
      hls.attachMedia(video);

      hls.on(Hls.Events.MANIFEST_PARSED, (_event, data) => {
        const levels = data.levels.map((lvl, idx) => ({
          id: idx,
          height: lvl.height || 720,
          name: lvl.height ? `${lvl.height}p` : `Level ${idx + 1}`,
        }));
        setQualities(levels);

        // Resume playback position if saved
        if (lesson.last_playback_position && lesson.last_playback_position > 0) {
          video.currentTime = lesson.last_playback_position;
        }
      });

      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (data.fatal) {
          switch (data.type) {
            case Hls.ErrorTypes.NETWORK_ERROR:
              hls.startLoad();
              break;
            case Hls.ErrorTypes.MEDIA_ERROR:
              hls.recoverMediaError();
              break;
            default:
              hls.destroy();
              setError('Secure stream could not be loaded. Please refresh your session.');
              break;
          }
        }
      });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      // Native Apple HLS support (Safari iOS / macOS)
      video.src = streamUrl;
      video.addEventListener('loadedmetadata', () => {
        if (lesson.last_playback_position && lesson.last_playback_position > 0) {
          video.currentTime = lesson.last_playback_position;
        }
      });
    }

    return () => {
      if (hlsRef.current) {
        hlsRef.current.destroy();
        hlsRef.current = null;
      }
    };
  }, [sessionData, lesson.last_playback_position]);

  // Handle periodic playback progress reporting
  const handleTimeUpdate = () => {
    if (!videoRef.current || !onProgress) return;
    const current = videoRef.current.currentTime;
    const dur = videoRef.current.duration || sessionData?.duration_seconds || 0;

    // Report every 4 seconds or when near end
    if (Math.abs(current - lastReportedTime.current) >= 4 || (dur > 0 && current >= dur - 1)) {
      lastReportedTime.current = current;
      onProgress(current, dur);
    }
  };

  const handleQualityChange = (levelIndex: number) => {
    setCurrentQuality(levelIndex);
    if (hlsRef.current) {
      hlsRef.current.currentLevel = levelIndex;
    }
  };

  const handleTamperDetected = () => {
    if (videoRef.current) {
      videoRef.current.pause();
    }
    setTamperAlert(true);
  };

  if (loading) {
    return (
      <div className="w-full aspect-video bg-slate-950 rounded-2xl overflow-hidden shadow-2xl flex flex-col items-center justify-center text-slate-400 gap-3 border border-slate-800">
        <span className="w-8 h-8 border-3 border-blue-500 border-t-transparent rounded-full animate-spin" />
        <span className="text-xs font-semibold tracking-wide">
          Authorizing Encrypted Video Stream & Session Key...
        </span>
      </div>
    );
  }

  if (error) {
    return (
      <div className="w-full aspect-video bg-slate-950 rounded-2xl overflow-hidden shadow-2xl flex flex-col items-center justify-center p-8 text-center border border-red-900/60">
        <span className="text-4xl block mb-3">🔒</span>
        <h3 className="text-base font-extrabold text-white mb-1">Playback Authorization Required</h3>
        <p className="text-xs text-red-300/90 max-w-md mb-6">{error}</p>
        <button
          type="button"
          onClick={fetchPlaybackAuthorization}
          className="py-2 px-5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-md transition"
        >
          Retry Playback Authorization
        </button>
      </div>
    );
  }

  const watermarkMobile = sessionData?.watermark?.mobile_number ?? '';

  return (
    <div className="flex flex-col space-y-4">
      {/* Video Viewport Container */}
      <div
        className="w-full aspect-video bg-black rounded-2xl overflow-hidden shadow-2xl relative border border-slate-800 select-none group"
        onContextMenu={(e) => e.preventDefault()}
      >
        {/* HTML5 Video Element */}
        <video
          ref={videoRef}
          controls
          playsInline
          controlsList="nodownload"
          disablePictureInPicture
          onTimeUpdate={handleTimeUpdate}
          className="w-full h-full object-contain relative z-10"
        >
          Your browser does not support secure HLS video playback.
        </video>

        {/* Dynamic Continuous Wandering Watermark Overlay */}
        <DynamicWatermark
          mobileNumber={watermarkMobile}
          onTamperDetected={handleTamperDetected}
        />

        {/* Tamper Alert Warning Overlay */}
        {tamperAlert && (
          <div className="absolute inset-0 bg-black/90 z-50 flex flex-col items-center justify-center p-6 text-center text-red-400">
            <span className="text-3xl mb-2">⚠️</span>
            <p className="text-xs font-black uppercase tracking-wider text-red-300">
              Security Overlay Alteration Detected
            </p>
            <p className="text-xs text-slate-400 mt-1 max-w-sm">
              Playback has been paused. Please restore default window display settings to continue.
            </p>
            <button
              type="button"
              onClick={() => setTamperAlert(false)}
              className="mt-4 py-1.5 px-4 rounded-lg bg-red-600 text-white text-xs font-bold"
            >
              Resume Stream
            </button>
          </div>
        )}

        {/* Adaptive Quality Selector Badge */}
        {qualities.length > 0 && (
          <div className="absolute top-3 right-3 z-30 opacity-0 group-hover:opacity-100 transition duration-200">
            <select
              value={currentQuality}
              onChange={(e) => handleQualityChange(Number(e.target.value))}
              className="px-2 py-1 rounded-lg bg-slate-900/80 border border-slate-700 text-white text-[11px] font-bold outline-none backdrop-blur-md cursor-pointer hover:bg-slate-800"
            >
              <option value={-1}>⚡ Quality: Auto (ABR)</option>
              {qualities.map((q) => (
                <option key={q.id} value={q.id}>
                  {q.name}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

      {/* Security Info Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 rounded-xl bg-slate-900/60 border border-slate-800/80 text-[11px] text-slate-400">
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-emerald-500" />
          <span className="font-semibold text-slate-300">
            Encrypted Adaptive Stream (AES-128)
          </span>
        </div>
        <div className="flex items-center gap-1.5 font-mono text-slate-400">
          <span>Watermark ID:</span>
          <span className="text-slate-200 font-bold">{watermarkMobile}</span>
        </div>
      </div>
    </div>
  );
}
