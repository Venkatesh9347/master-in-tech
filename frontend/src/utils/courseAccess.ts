/**
 * Generates the course-specific localStorage key for gating course learning access.
 * Format: masterintech_public_access_completed_<courseId>
 */
export const getCourseAccessStorageKey = (courseId?: number | string | null): string => {
  return courseId
    ? `masterintech_public_access_completed_${courseId}`
    : 'masterintech_public_access_completed_general';
};
