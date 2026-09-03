export interface ClassroomParticipant {
  id: number;
  class_session_id?: number | null;
  live_classroom_session_id?: number | null;
  user_id: number;
  role: 'host' | 'co-host' | 'participant';
  is_host_active: boolean;
  is_mic_allowed: boolean;
  is_camera_allowed: boolean;
  is_chat_allowed: boolean;
  is_hand_raised: boolean;
  hand_raised_at?: string | null;
  joined_at?: string | null;
  left_at?: string | null;
  duration_seconds: number;
  connection_state: string;
  user?: {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role?: string | null;
  };
}

export interface ClassroomPermissionRequest {
  id: number;
  class_session_id?: number | null;
  live_classroom_session_id?: number | null;
  user_id: number;
  type: 'speak' | 'camera' | 'chat';
  status: 'pending' | 'approved' | 'denied' | 'cancelled';
  requested_at: string;
  resolved_at?: string | null;
  resolved_by?: number | null;
  user?: {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
  };
}

export interface ClassroomMessage {
  id: number;
  class_session_id?: number | null;
  live_classroom_session_id?: number | null;
  user_id: number;
  message: string;
  is_pinned: boolean;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role?: string | null;
  };
}

export interface ClassroomStateResponse {
  session: {
    id: number;
    title: string;
    status: string;
    current_host_id?: number | null;
    active_host_id?: number | null;
    is_chat_enabled: boolean;
    tutor?: {
      id: number;
      name: string;
      email: string;
    } | null;
  };
  is_host: boolean;
  my_participant: ClassroomParticipant;
  participants: ClassroomParticipant[];
  pending_requests: ClassroomPermissionRequest[];
  messages: ClassroomMessage[];
}
