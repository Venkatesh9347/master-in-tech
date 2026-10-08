import type { Course } from '../../types/course';
import CourseCard from './CourseCard';
import { StaggerContainer, StaggerItem } from '../motion';
import { EmptyState } from '../dashboard';

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
    // Empty state gets an entrance, but stays a single readable message.
    return (
      <div className="mit-enter-rise py-12">
        <EmptyState title={emptyMessage} description="Try a different search or filter." />
      </div>
    );
  }

  // Cards stagger in once, then hold still. Pagination/filter changes re-run
  // the stagger because the list remounts, which reads as a page transition.
  return (
    <StaggerContainer className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {courses.map((course, index) => (
        <StaggerItem key={course.id} index={index}>
          <CourseCard
            course={course}
            linkTo={linkPrefix ? `${linkPrefix}/${course.id}` : undefined}
            buttonText={buttonText}
            onButtonClick={onButtonClick}
          />
        </StaggerItem>
      ))}
    </StaggerContainer>
  );
}
