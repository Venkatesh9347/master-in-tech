export type LiveClassStatus = 'scheduled' | 'live' | 'completed' | 'cancelled';
export type VideoProviderType = 'zoom' | 'teams' | 'google_meet' | 'jitsi' | 'custom';

export interface LiveClassInstructor {
  id: number;
  name: string;
  email: string;
  role?: string;
  avatar?: string;
  headline?: string;
}

export interface LiveClassCourse {
  id: number;
  title: string;
  category?: string;
  thumbnail?: string;
}

export interface LiveClass {
  id: number;
  course_id: number;
  instructor_id: number;
  title: string;
  description?: string;
  class_date: string;
  start_time: string;
  end_time?: string;
  duration_minutes: number;
  status: LiveClassStatus;
  provider: VideoProviderType;
  meeting_id?: string;
  meeting_url?: string;
  host_url?: string;
  passcode?: string;
  is_chat_enabled: boolean;
  is_mic_allowed_by_default: boolean;
  provider_metadata?: Record<string, unknown>;
  settings?: Record<string, unknown>;
  started_at?: string;
  ended_at?: string;
  created_at?: string;
  course?: LiveClassCourse;
  instructor?: LiveClassInstructor;
}

export interface LiveClassAttendance {
  id: number;
  live_class_id: number;
  user_id: number;
  course_id: number;
  joined_at: string;
  left_at?: string;
  duration_seconds: number;
  status: 'present' | 'attended' | 'left';
  is_hand_raised: boolean;
  hand_raised_at?: string;
  is_mic_allowed: boolean;
  is_muted: boolean;
  is_removed: boolean;
  user?: {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role?: string;
    phone?: string;
  };
}

export interface LiveClassMessage {
  id: number;
  live_class_id: number;
  user_id: number;
  message: string;
  is_announcement: boolean;
  is_pinned: boolean;
  created_at: string;
  user?: {
    id: number;
    name: string;
    role?: string;
    avatar?: string;
  };
}

export interface LiveClassState {
  status: LiveClassStatus;
  is_chat_enabled: boolean;
  active_count: number;
  my_attendance?: LiveClassAttendance;
  raised_hands: LiveClassAttendance[];
  participants?: LiveClassAttendance[];
}
