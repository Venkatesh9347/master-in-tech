import { Suspense, lazy, useEffect, useRef } from 'react';
import type { Lesson } from '../../types/lms';

/**
 * B19: `hls.js` (618 kB minified) is only needed for the protected/self-hosted
 * playback branch below. Importing it eagerly pulled the whole library into the
 * StudentLessons route chunk, which became the largest in the build (630 kB)
 * even though most lesson views never play an HLS stream.
 *
 * Lazy-loading keeps the library out of the route chunk and out of the initial
 * download; it is fetched the first time a protected video is actually shown.
 */
const SecureVideoPlayer = lazy(() => import('./SecureVideoPlayer'));

interface VideoPlayerProps {
  lesson: Lesson;
  courseId?: number;
  onProgress?: (currentTime: number, duration: number) => void;
}

const YOUTUBE_ORIGIN = 'https://www.youtube-nocookie.com';

/** Matches the aspect-video box used for the player, so layout does not shift. */
function PlayerFallback() {
  return (
    <div
      className="w-full aspect-video bg-slate-900 rounded-2xl overflow-hidden shadow-lg ring-1 ring-slate-800 flex items-center justify-center"
      role="status"
      aria-live="polite"
    >
      <span className="text-xs font-semibold text-slate-400">Loading secure player…</span>
    </div>
  );
}

/**
 * Metered YouTube embed (IFrame Player API over postMessage, no extra
 * dependency). Playback time/duration flow into the standard onProgress
 * heartbeat so the backend 90% watch gate sees real evidence — an
 * unplayed embed records nothing and completion stays blocked, exactly
 * like the secure player path. Anything unrecognized is ignored (fail
 * closed: no fabricated progress).
 *
 * The channel uses unambiguous single-key command events: after the
 * player reports onReady we poll getDuration/getCurrentTime, and the
 * player answers each poll with an infoDelivery payload. Playback end
 * (onStateChange 0) reports a final full watch from the last known
 * duration.
 */
function YouTubeEmbed({
  embedUrl,
  title,
  onProgress,
}: {
  embedUrl: string;
  title: string;
  onProgress?: (currentTime: number, duration: number) => void;
}) {
  const iframeRef = useRef<HTMLIFrameElement | null>(null);
  const onProgressRef = useRef(onProgress);
  const lastKnown = useRef<{ t: number; d: number }>({ t: 0, d: 0 });

  useEffect(() => {
    onProgressRef.current = onProgress;
  }, [onProgress]);

  useEffect(() => {
    lastKnown.current = { t: 0, d: 0 };
    let pollTimer: ReturnType<typeof setInterval> | null = null;

    const post = (message: Record<string, unknown>) => {
      try {
        iframeRef.current?.contentWindow?.postMessage(JSON.stringify(message), YOUTUBE_ORIGIN);
      } catch {
        // Cross-origin post failures must never break the lesson view.
      }
    };

    const reportProgress = (t: number, d: number) => {
      if (Number.isFinite(t) && Number.isFinite(d) && d > 0 && t >= 0) {
        lastKnown.current = { t, d };
        onProgressRef.current?.(t, d);
      }
    };

    const handleMessage = (event: MessageEvent) => {
      if (event.origin !== YOUTUBE_ORIGIN) return;

      let parsed: unknown;
      try {
        parsed = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
      } catch {
        return;
      }
      if (!parsed || typeof parsed !== 'object') return;
      const data = parsed as { event?: unknown; info?: unknown };
      if (typeof data.event !== 'string') return;

      if (data.event === 'onReady') {
        // Open the metering channel with unambiguous single-key command
        // events; the player answers each poll with an infoDelivery
        // payload carrying currentTime/duration.
        post({ event: 'getDuration', id: 1 });
        post({ event: 'getCurrentTime', id: 1 });
        if (pollTimer === null) {
          pollTimer = setInterval(() => {
            post({ event: 'getDuration', id: 1 });
            post({ event: 'getCurrentTime', id: 1 });
          }, 5000);
        }
      } else if (data.event === 'infoDelivery') {
        const info = data.info as { currentTime?: unknown; duration?: unknown } | null;
        const t = typeof info?.currentTime === 'number' ? info.currentTime : NaN;
        const d = typeof info?.duration === 'number' ? info.duration : NaN;
        reportProgress(t, d);
      } else if (data.event === 'onStateChange' && data.info === 0) {
        // Playback ended: report full watch from the last known duration.
        const { t, d } = lastKnown.current;
        if (d > 0) {
          onProgressRef.current?.(d, d);
        } else if (t > 0) {
          onProgressRef.current?.(t, t);
        }
      }
    };

    window.addEventListener('message', handleMessage);
    return () => {
      window.removeEventListener('message', handleMessage);
      if (pollTimer !== null) clearInterval(pollTimer);
    };
  }, [embedUrl]);

  return (
    <div className="w-full aspect-video bg-slate-900 rounded-2xl overflow-hidden shadow-lg ring-1 ring-slate-800 flex items-center justify-center">
      <iframe
        ref={iframeRef}
        src={embedUrl}
        title={title}
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowFullScreen
        className="w-full h-full border-0"
      />
    </div>
  );
}

export default function VideoPlayer({ lesson, courseId, onProgress }: VideoPlayerProps) {
  const videoUrl = lesson.metadata?.video_url || '';

  // Check if an external third-party embed is explicitly provided (e.g. YouTube/Vimeo fallback)
  const isExternalEmbed =
    videoUrl.includes('youtube.com') ||
    videoUrl.includes('youtu.be') ||
    videoUrl.includes('vimeo.com');

  const getEmbedUrl = (url: string): string | null => {
    const ytMatch = url.match(
      /(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([\w-]{11})/
    );
    if (ytMatch && ytMatch[1]) {
      // enablejsapi=1 opens the postMessage metering channel consumed above.
      return `https://www.youtube-nocookie.com/embed/${ytMatch[1]}?rel=0&autoplay=0&enablejsapi=1`;
    }
    const vimeoMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (vimeoMatch && vimeoMatch[1]) {
      return `https://player.vimeo.com/video/${vimeoMatch[1]}`;
    }
    return null;
  };

  const embedUrl = isExternalEmbed ? getEmbedUrl(videoUrl) : null;
  const isYouTube = embedUrl !== null && embedUrl.includes('youtube-nocookie.com');

  return (
    <div className="flex flex-col space-y-6">
      {/* Video Viewport Stage */}
      {embedUrl ? (
        isYouTube ? (
          <YouTubeEmbed embedUrl={embedUrl} title={lesson.title} onProgress={onProgress} />
        ) : (
          <div className="w-full aspect-video bg-slate-900 rounded-2xl overflow-hidden shadow-lg ring-1 ring-slate-800 flex items-center justify-center">
            <iframe
              src={embedUrl}
              title={lesson.title}
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
              allowFullScreen
              className="w-full h-full border-0"
            />
          </div>
        )
      ) : (
        <Suspense fallback={<PlayerFallback />}>
          <SecureVideoPlayer
            lesson={lesson}
            courseId={courseId || lesson.course_id}
            onProgress={onProgress}
          />
        </Suspense>
      )}

      {/* Lesson Details & Curriculum Notes */}
      <div className="bg-white p-6 rounded-2xl ring-1 ring-slate-200 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
          <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
            📹 Protected Video Lesson
          </span>
          {lesson.duration && (
            <span className="text-xs font-semibold text-slate-500">
              ⏱ {lesson.duration}
            </span>
          )}
        </div>

        <h1 className="text-2xl font-extrabold text-slate-900 mb-3">
          {lesson.title}
        </h1>

        {lesson.description && (
          <p className="text-base text-slate-600 leading-relaxed mb-4">
            {lesson.description}
          </p>
        )}

        {lesson.metadata?.content && (
          <div className="mt-4 pt-4 border-t border-slate-100 prose prose-slate max-w-none text-slate-700 text-sm">
            <h3 className="text-base font-bold text-slate-900 mb-2">Lesson Notes</h3>
            <p className="whitespace-pre-line leading-relaxed">{lesson.metadata.content}</p>
          </div>
        )}
      </div>
    </div>
  );
}
