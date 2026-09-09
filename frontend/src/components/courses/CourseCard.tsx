import { memo, useState } from 'react';
import { Link } from 'react-router-dom';
import type { Course } from '../../types/course';

interface CourseCardProps {
  course: Course;
  linkTo?: string;
  buttonText?: string;
  onButtonClick?: (course: Course) => void;
  onEnquireClick?: (course: Course) => void;
}

function CourseCardComponent({
  course,
  linkTo = `/courses/${course.id}`,
  buttonText = 'Download Brochure',
  onButtonClick,
  onEnquireClick,
}: CourseCardProps) {
  const [toastMsg, setToastMsg] = useState('');

  const getDifficultyBadge = (diff?: string) => {
    switch (diff?.toLowerCase()) {
      case 'basic':
      case 'beginner':
        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
      case 'intermediate':
        return 'bg-blue-50 text-blue-700 border-blue-200';
      case 'advanced':
        return 'bg-purple-50 text-purple-700 border-purple-200';
      default:
        return 'bg-slate-100 text-slate-700 border-slate-200';
    }
  };

  const lessonsCount =
    course.lessons_count ||
    course.sections?.reduce((acc, s) => acc + (s.lessons?.length || 0), 0) ||
    0;

  const handleBrochureClick = (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();

    if (onButtonClick) {
      onButtonClick(course);
      return;
    }

    const brochureUrl = course.brochure;
    if (!brochureUrl) {
      setToastMsg('Brochure currently unavailable.');
      setTimeout(() => setToastMsg(''), 4000);
      return;
    }

    // Try downloading the PDF or fallback to new tab
    try {
      const link = document.createElement('a');
      link.href = brochureUrl;
      link.setAttribute('download', `${course.title.replace(/[^a-zA-Z0-9_-]/g, '_')}_Brochure.pdf`);
      link.setAttribute('target', '_blank');
      link.setAttribute('rel', 'noopener noreferrer');
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    } catch {
      window.open(brochureUrl, '_blank', 'noopener,noreferrer');
    }
  };

  return (
    <div className="group rounded-xl bg-white border border-slate-200 shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-200 flex flex-col justify-between overflow-hidden relative">
      {/* Toast message if brochure is unavailable */}
      {toastMsg && (
        <div className="absolute top-2 left-2 right-2 z-30 p-2 bg-slate-950/95 text-amber-300 border border-amber-500/50 rounded-lg text-[11px] font-bold text-center shadow-xl backdrop-blur-xs flex items-center justify-center gap-1.5 animate-pulse">
          <span>⚠️</span> {toastMsg}
        </div>
      )}

      {/* Thumbnail Banner with explicit aspect-ratio and lazy loading */}
      <div className="relative h-44 aspect-[16/9] w-full bg-slate-900 overflow-hidden">
        {course.thumbnail ? (
          <img
            src={course.thumbnail}
            alt={course.title}
            loading="lazy"
            decoding="async"
            className="w-full h-full object-cover opacity-85 group-hover:scale-102 transition duration-300"
            onError={(e) => {
              ;(e.target as HTMLImageElement).src =
                'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=800&q=80';
            }}
          />
        ) : (
          <div className="w-full h-full bg-gradient-to-br from-slate-900 via-slate-800 to-blue-950 p-4 flex flex-col justify-between text-white">
            <span className="text-xs font-bold text-blue-400 uppercase tracking-wider">
              {course.category}
            </span>
            <span className="text-base font-bold text-white line-clamp-2">
              {course.title}
            </span>
          </div>
        )}
        <div className="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-transparent pointer-events-none" />

        {/* Category Pill */}
        <div className="absolute top-3 left-3 z-10">
          {course.category && (
            <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md bg-slate-900/90 text-white backdrop-blur-sm border border-slate-700/60">
              {course.category}
            </span>
          )}
        </div>

        {/* Level Pill */}
        <div className="absolute top-3 right-3 z-10">
          <span
            className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-md border ${getDifficultyBadge(
              course.difficulty
            )}`}
          >
            {course.difficulty}
          </span>
        </div>

        {/* Duration & Lessons Meta */}
        <div className="absolute bottom-2.5 left-3 right-3 z-10 flex items-center justify-between text-[11px] font-medium text-slate-200">
          {course.duration ? <span>⏱️ {course.duration}</span> : <span />}
          {lessonsCount > 0 ? <span>📖 {lessonsCount} Lessons</span> : null}
        </div>
      </div>

      {/* Card Content */}
      <div className="p-4 flex flex-col flex-grow">
        {/* Title */}
        <Link to={linkTo} className="group-hover:text-blue-600 transition">
          <h3 className="text-sm font-bold text-slate-900 line-clamp-2 leading-snug mb-1.5">
            {course.title}
          </h3>
        </Link>

        {/* Description */}
        <p className="text-xs text-slate-600 line-clamp-2 mb-3 leading-relaxed">
          {course.description}
        </p>

        {/* Instructor */}
        <div className="mt-auto pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
          {course.instructor ? (
            <div className="flex items-center gap-1.5">
              <span className="text-[11px] text-slate-400">Instructor:</span>
              <span className="font-semibold text-slate-700 truncate max-w-[140px]">
                {course.instructor}
              </span>
            </div>
          ) : (
            <span />
          )}
          {course.average_rating ? (
            <div className="flex items-center gap-1 text-[11px] font-bold text-amber-600">
              <span>★</span>
              <span>{course.average_rating.toFixed(1)}</span>
            </div>
          ) : null}
        </div>

        {/* Actions Footer */}
        <div className="mt-3 pt-2.5 border-t border-slate-100">
          {course.is_enrolled ? (
            <div className="space-y-1.5">
              {course.progress_percentage !== undefined && (
                <div className="flex items-center justify-between text-[11px] font-semibold">
                  <span className="text-slate-500">Progress</span>
                  <span className="text-blue-600">
                    {Math.round(course.progress_percentage)}%
                  </span>
                </div>
              )}
              <Link
                to={`/student/courses/${course.id}/lessons`}
                className="w-full py-2 px-3 rounded-lg text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 text-center transition flex items-center justify-center gap-1"
              >
                Continue Learning →
              </Link>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-2">
              {onEnquireClick ? (
                <button
                  type="button"
                  onClick={(e) => {
                    e.preventDefault();
                    onEnquireClick(course);
                  }}
                  className="py-1.5 px-2.5 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition text-center cursor-pointer"
                >
                  Syllabus Info
                </button>
              ) : (
                <Link
                  to={linkTo}
                  className="py-1.5 px-2.5 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition text-center"
                >
                  Syllabus Info
                </Link>
              )}

              <button
                type="button"
                onClick={handleBrochureClick}
                className="py-1.5 px-2 rounded-lg text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition text-center cursor-pointer flex items-center justify-center gap-1 shadow-xs"
                title="Download Course Brochure PDF"
              >
                <span>📥</span> {buttonText}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

const CourseCard = memo(CourseCardComponent);
export default CourseCard;
