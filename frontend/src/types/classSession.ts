import type { Course } from './course'
import type { User } from '../context/auth-context'

export interface ClassMaterial {
  id: number
  course_id: number
  class_session_id?: number | null
  uploaded_by: number
  title: string
  description?: string | null
  file_path: string
  file_name: string
  file_type?: string | null
  file_size: number
  created_at: string
  updated_at: string
  uploader?: User
}

export interface ClassSessionAttendance {
  id: number
  class_session_id: number
  user_id: number
  status: 'present' | 'absent'
  joined_at?: string | null
  left_at?: string | null
  created_at: string
  user?: User
}

export interface ClassQuizInfo {
  id: number
  title: string
  description?: string | null
  time_limit?: number | null
  passing_score?: number
  questions_count: number
  status: 'Available' | 'Completed' | 'Not assigned' | string
  attempt_status: 'Not started' | 'In Progress' | 'Completed' | string
  score?: number | null
  total_marks?: number | null
  passed?: boolean | null
}

export interface ClassSession {
  id: number
  course_id: number
  course_title?: string
  course_code?: string | null
  batch_code?: string | null
  batch_number?: string | null
  batch?: {
    id: number
    code: string
    name?: string | null
    start_date?: string | null
  } | null
  tutor_id: number
  tutor_name?: string
  tutor_avatar?: string | null
  title: string
  description?: string | null
  platform: 'zoom' | 'teams' | 'livekit' | string
  meeting_url?: string | null
  meeting_id?: string | null
  meeting_password?: string | null
  masked_password?: string | null
  livekit_room_name?: string | null
  livekit_status?: string | null
  is_chat_enabled?: boolean
  current_host_id?: number | null
  scheduled_date: string
  start_time: string
  end_time: string
  status: 'scheduled' | 'live' | 'expired' | 'completed' | 'cancelled' | string
  expired_at?: string | null
  ended_at?: string | null
  expired_at_formatted?: string | null
  admin_notes?: string | null
  recording_url?: string | null
  created_by?: number
  created_by_name?: string | null
  updated_by?: number | null
  updated_by_name?: string | null
  created_at?: string
  updated_at?: string
  course?: Course
  tutor?: User
  creator?: User
  updater?: User
  materials?: ClassMaterial[]
  materials_count?: number
  materials_text?: string
  materials_shared_text?: string
  attendances?: ClassSessionAttendance[]
  attendances_count?: number
  attendance_status?: 'Present' | 'Absent' | 'Not Recorded' | string
  quiz?: ClassQuizInfo | null
  quiz_info?: ClassQuizInfo | null
  quiz_status?: 'Available' | 'Completed' | 'Not assigned' | string
}

export interface EnrichedPreviousSession extends ClassSession {
  course_title: string
  tutor_name: string
  materials: ClassMaterial[]
  materials_count: number
  materials_text: string
  attendance_status: string
  quiz_status: string
}
