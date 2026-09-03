import SecureVideoPlayer from './SecureVideoPlayer';
import type { Lesson } from '../../types/lms';

interface VideoPlayerProps {
  lesson: Lesson;
  courseId?: number;
  onProgress?: (currentTime: number, duration: number) => void;
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
      return `https://www.youtube-nocookie.com/embed/${ytMatch[1]}?rel=0&autoplay=0`;
    }
    const vimeoMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (vimeoMatch && vimeoMatch[1]) {
      return `https://player.vimeo.com/video/${vimeoMatch[1]}`;
    }
    return null;
  };

  const embedUrl = isExternalEmbed ? getEmbedUrl(videoUrl) : null;

  return (
    <div className="flex flex-col space-y-6">
      {/* Video Viewport Stage */}
      {embedUrl ? (
        <div className="w-full aspect-video bg-slate-900 rounded-2xl overflow-hidden shadow-lg ring-1 ring-slate-800 flex items-center justify-center">
          <iframe
            src={embedUrl}
            title={lesson.title}
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowFullScreen
            className="w-full h-full border-0"
          />
        </div>
      ) : (
        <SecureVideoPlayer
          lesson={lesson}
          courseId={courseId || lesson.course_id}
          onProgress={onProgress}
        />
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
