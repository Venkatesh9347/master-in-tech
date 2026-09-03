export interface Speaker {
  name: string;
  designation: string;
  image?: string;
}

export type EventMode = 'online' | 'offline';
export type EventStatus = 'draft' | 'published' | 'completed' | 'cancelled';
export type RegistrationStatus = 'registered' | 'cancelled' | 'completed';
export type AttendanceStatus = 'pending' | 'attended' | 'absent';

export interface Event {
  id: number;
  title: string;
  slug: string;
  description: string;
  short_description?: string;
  banner?: string;
  category: string;
  speaker_name: string;
  speaker_designation: string;
  speaker_image?: string;
  event_date: string; // ISO datetime string
  start_time: string; // HH:mm format
  end_time: string; // HH:mm format
  duration: number; // in minutes
  mode: EventMode;
  meeting_url?: string;
  location?: string;
  price: number; // in decimal format
  registration_limit?: number;
  registered_count: number;
  status: EventStatus;
  created_at?: string;
  updated_at?: string;
}

export interface EventRegistration {
  id: number;
  event_id: number;
  user_id: number;
  status: RegistrationStatus;
  attendance_status: AttendanceStatus;
  registered_at: string;
  created_at?: string;
  updated_at?: string;
  event?: Event;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}

export interface EventListResponse {
  data: Event[];
}

export interface EventRegistrationResponse {
  message: string;
  registration: EventRegistration;
}

export interface EventCheckRegistrationResponse {
  registered: boolean;
  status?: RegistrationStatus;
  registration?: EventRegistration;
}

export interface AdminEventStatsResponse {
  total_events: number;
  upcoming_events: number;
  completed_events: number;
  total_registrations: number;
}

export interface AdminEventRegistrationsResponse {
  event: Event;
  registrations: EventRegistration[];
}
