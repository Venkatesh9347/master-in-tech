export interface LiveClassroomSession {
  id: number;
  room_id: string;
  batch_id: number;
  course_id?: number | null;
  tutor_id?: number | null;
  title: string;
  description?: string | null;
  scheduled_date: string;
  start_time: string;
  end_time?: string | null;
  status: 'scheduled' | 'live' | 'completed' | 'cancelled';
  started_at?: string | null;
  ended_at?: string | null;
  settings?: {
    is_mic_allowed_by_default?: boolean;
    is_camera_allowed_by_default?: boolean;
    [key: string]: unknown;
  } | null;
  batch?: {
    id: number;
    code: string;
    name?: string | null;
  } | null;
  course?: {
    id: number;
    title: string;
    code?: string | null;
    category?: string | null;
    thumbnail?: string | null;
  } | null;
  tutor?: {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    headline?: string | null;
  } | null;
  participants?: LiveClassroomParticipant[];
}

export interface LiveClassroomParticipant {
  id: number;
  live_classroom_session_id: number;
  user_id: number;
  role: 'host' | 'participant' | 'co-host';
  joined_at?: string | null;
  left_at?: string | null;
  duration_seconds: number;
  is_mic_allowed: boolean;
  is_camera_allowed: boolean;
  is_hand_raised: boolean;
  user?: {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role?: string | null;
  };
}

export interface LiveClassroomTokenResponse {
  token: string;
  ws_url: string;
  room_id: string;
  session: {
    id: number;
    room_id: string;
    title: string;
    status: 'scheduled' | 'live' | 'completed' | 'cancelled';
    scheduled_date: string;
    start_time: string;
    end_time?: string | null;
    batch?: {
      id: number;
      code: string;
      name?: string | null;
    } | null;
    course?: {
      id: number;
      title: string;
    } | null;
    tutor?: {
      id: number;
      name: string;
    } | null;
  };
  is_host: boolean;
  role: 'host' | 'participant';
  can_publish: boolean;
  is_mic_allowed: boolean;
  is_camera_allowed: boolean;
  participant?: LiveClassroomParticipant;
}
