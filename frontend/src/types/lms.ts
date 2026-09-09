// LMS Type Definitions for Phase 3

export type LessonType = 'video' | 'text' | 'document' | 'quiz' | 'assignment';

export interface LessonMetadata {
  video_url?: string;
  video_title?: string;
  duration?: string;
  thumbnail?: string;
  content?: string;
  document_url?: string;
  document_title?: string;
}

export interface LessonResource {
  id: number;
  lesson_id: number;
  title: string;
  file_url: string;
  file_size: string | null;
  description: string | null;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export interface Section {
  id: number;
  course_id: number;
  title: string;
  slug: string | null;
  description: string | null;
  sort_order: number;
  is_published: boolean;
  created_at: string;
  updated_at: string;
  lessons?: Lesson[];
}

export interface Lesson {
  id: number;
  section_id: number;
  course_id: number;
  title: string;
  slug: string | null;
  description: string | null;
  duration: string | null;
  type: LessonType;
  metadata: LessonMetadata | null;
  sort_order: number;
  is_published: boolean;
  created_at: string;
  updated_at: string;
  completed?: boolean;
  status?: 'not_started' | 'in_progress' | 'completed';
  started?: boolean;
  progress_percentage?: number;
  last_playback_position?: number;
  duration_seconds?: number;
  last_accessed_at?: string | null;
  quiz?: Quiz;
  assignment?: Assignment;
  resources?: LessonResource[];
}

export interface Quiz {
  id: number;
  lesson_id: number;
  title: string;
  description: string | null;
  time_limit: number | null;
  max_attempts: number | null;
  passing_score: number;
  randomize_questions: boolean;
  is_published: boolean;
  created_at: string;
  updated_at: string;
  questions?: QuizQuestion[];
}

export interface QuizQuestion {
  id: number;
  quiz_id: number;
  question: string;
  type: string;
  sort_order: number;
  marks: number;
  is_published: boolean;
  options?: QuizOption[];
}

export interface QuizOption {
  id: number;
  question_id: number;
  option_text: string;
  is_correct: boolean;
  sort_order: number;
}

export interface QuizAttempt {
  id: number;
  user_id: number;
  quiz_id: number;
  lesson_id: number;
  course_id: number;
  section_id: number | null;
  started_at: string | null;
  completed_at: string | null;
  score: number;
  total_marks: number;
  passing_score: number;
  passed: boolean;
  status: 'started' | 'completed';
  attempt_number: number;
  created_at: string;
  updated_at: string;
  user?: {
    id?: number;
    name?: string;
    email?: string;
  };
}

export interface QuizAnswer {
  id: number;
  quiz_attempt_id: number;
  question_id: number;
  option_id: number | null;
  answer_text: string | null;
  is_correct: boolean;
  marks_awarded: number;
  created_at: string;
  updated_at: string;
}

export interface Assignment {
  id: number;
  lesson_id: number;
  course_id: number;
  title: string;
  instructions: string | null;
  due_date: string | null;
  max_marks: number;
  file_url: string | null;
  is_published: boolean;
  created_at: string;
  updated_at: string;
}

export interface AssignmentSubmission {
  id: number;
  user_id: number;
  assignment_id: number;
  lesson_id: number;
  course_id: number;
  submission_text: string | null;
  file_url: string | null;
  submitted_at: string | null;
  score: number | null;
  feedback: string | null;
  status: 'submitted' | 'graded' | 'returned';
  created_at: string;
  updated_at: string;
}

export interface CourseEnrollment {
  id: number;
  user_id: number;
  course_id: number;
  enrolled_at: string;
  status: 'active' | 'completed' | 'dropped';
  progress_percentage: number;
  created_at: string;
  updated_at: string;
  course?: {
    id: number;
    title: string;
    description: string;
    instructor: string;
    price: number;
    duration: string;
    difficulty: string;
  };
}

export interface CourseProgress {
  course_id: number;
  course_title: string;
  progress_percentage: number;
  progress_percent?: number;
  completed_lessons: number[];
  completed_lesson_count: number;
  total_lessons: number;
  last_accessed_lesson_id?: number | null;
  is_course_completed?: boolean;
  course_completed?: boolean;
  current_lesson: Lesson | null;
  enrollment: {
    status: string;
    progress_percentage: number;
    enrolled_at: string;
  };
  sections: Section[];
}

export interface QuizQuestionWithAnswers {
  id: number;
  attempt_id: number;
  question: string;
  marks: number;
  selected_option_id?: number | null;
  is_correct: boolean;
  marks_awarded: number;
}

export interface LessonDiscussionReply {
  id: number;
  discussion_id: number;
  user_id: number;
  reply_text: string;
  created_at: string;
  user?: {
    id: number;
    name: string;
    role: string;
  };
}

export interface LessonDiscussion {
  id: number;
  user_id: number;
  course_id: number;
  lesson_id: number;
  question_text: string;
  created_at: string;
  user?: {
    id: number;
    name: string;
    role: string;
  };
  replies?: LessonDiscussionReply[];
}

export interface LessonNoteData {
  note: string;
  updated_at: string | null;
}
