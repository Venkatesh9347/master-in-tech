export type CourseDifficulty = 'Beginner' | 'Intermediate' | 'Advanced';

export interface CourseSection {
  id: number;
  course_id: number;
  title: string;
  slug?: string;
  description?: string;
  sort_order: number;
  is_published?: boolean;
  lessons?: CourseLesson[];
}

export interface CourseLesson {
  id: number;
  course_id: number;
  section_id: number;
  title: string;
  slug?: string;
  description?: string;
  type: 'video' | 'text' | 'document' | 'quiz' | 'assignment';
  duration?: string;
  sort_order: number;
  is_published?: boolean;
}

export interface Course {
  id: number;
  title: string;
  slug?: string;
  description: string;
  full_description?: string;
  category?: string;
  thumbnail?: string;
  banner?: string;
  brochure?: string;
  media_id?: number;
  brochure_media_id?: number;
  image?: string;
  instructor: string;
  instructor_id?: number | null;
  price?: number;
  internal_price?: number;
  duration: string;
  difficulty: string;
  prerequisites?: string[];
  learning_objectives?: string[];
  skills_gained?: string[];
  average_rating?: number;
  reviews_count?: number;
  students_count?: number;
  sections_count?: number;
  lessons_count?: number;
  enrollments_count?: number;
  is_published?: boolean;
  status?: 'draft' | 'published' | string;
  priority?: number;
  is_enrolled?: boolean;
  progress_percentage?: number;
  completed_lessons_count?: number;
  sections?: CourseSection[];
}

export interface CourseListResponse {
  data: Course[];
}
