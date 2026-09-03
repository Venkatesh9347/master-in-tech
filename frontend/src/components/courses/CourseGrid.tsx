import type { Course } from '../../types/course';
import CourseCard from './CourseCard';

interface CourseGridProps {
  courses: Course[];
  /** Prefix path for each card's link (e.g. '/courses' or '/student/courses'). */
  linkPrefix?: string;
  buttonText?: string;
  onButtonClick?: (course: Course) => void;
  emptyMessage?: string;
}

export default function CourseGrid({
  courses,
  linkPrefix,
  buttonText,
  onButtonClick,
  emptyMessage = 'No courses found.',
}: CourseGridProps) {
  if (courses.length === 0) {
    return (
      <div className="text-center py-12">
        <p className="text-slate-600 text-lg">{emptyMessage}</p>
      </div>
    );
  }

  return (
    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {courses.map((course) => (
        <CourseCard
          key={course.id}
          course={course}
          linkTo={linkPrefix ? `${linkPrefix}/${course.id}` : undefined}
          buttonText={buttonText}
          onButtonClick={onButtonClick}
        />
      ))}
    </div>
  );
}
