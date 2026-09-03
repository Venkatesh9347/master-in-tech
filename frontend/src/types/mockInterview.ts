export interface AdminMockInterviewerItem {
  id: number
  user_id?: number | null
  name: string
  email: string
  phone?: string | null
  designation: string
  company: string
  years_of_experience: number
  skills?: string[] | null
  bio?: string | null
  internal_notes?: string | null
  is_active: boolean
  slots_count?: number
  interviews_count?: number
  evaluations_count?: number
  created_at: string
}

export interface AdminMockSlotItem {
  id: number
  interviewer_id: number
  slot_date: string
  start_time: string
  end_time: string
  duration_minutes: number
  meeting_link?: string | null
  platform: string
  status: 'available' | 'booked' | 'completed' | 'cancelled'
  instructions?: string | null
  interviewer?: AdminMockInterviewerItem | null
  interview?: {
    id: number
    booking_code: string
    status: string
    student?: { id: number; name: string; email: string } | null
  } | null
}

export interface AdminMockEvaluationItem {
  id: number
  mock_interview_id: number
  interviewer_id?: number | null
  evaluated_by?: number | null
  student_id: number
  technical_knowledge: number
  programming_problem_solving: number
  communication: number
  confidence: number
  project_knowledge: number
  interview_readiness: number
  overall_rating: number
  strengths: string
  areas_for_improvement: string
  interviewer_remarks?: string | null
  recommendation: 'Ready for Placement' | 'Needs Improvement' | 'Re-interview Required'
  is_published_to_student: boolean
  evaluated_at: string
  evaluator?: { id: number; name: string; email: string } | null
  interviewer?: AdminMockInterviewerItem | null
  student?: { id: number; name: string; email: string } | null
}

export interface AdminMockInterviewItem {
  id: number
  booking_code: string
  student_id: number
  slot_id: number
  interviewer_id: number
  course_id?: number | null
  batch_id?: number | null
  scheduled_at: string
  status: 'booked' | 'confirmed' | 'completed' | 'cancelled' | 'rescheduled' | 'no_show'
  student_notes?: string | null
  admin_notes?: string | null
  cancellation_reason?: string | null
  student?: { id: number; name: string; email: string; phone?: string; student_id?: string } | null
  slot?: AdminMockSlotItem | null
  interviewer?: AdminMockInterviewerItem | null
  course?: { id: number; title: string; code?: string } | null
  batch?: { id: number; name: string; code: string } | null
  evaluation?: AdminMockEvaluationItem | null
  canceller?: { id: number; name: string } | null
  created_at: string
}

export interface StudentEligibilityData {
  student_id: number
  student_name: string
  student_email: string
  phone?: string | null
  student_code?: string | null
  is_eligible: boolean
  course_completed: boolean
  certificates_count: number
  is_admin_override: boolean
  override_reason?: string | null
  reasons: string[]
  courses: Array<{
    course_id: number
    course_title: string
    course_code?: string
    status: string
    total_lessons: number
    completed_lessons: number
    progress_percentage: number
    is_completed: boolean
  }>
  batch?: {
    id: number
    name: string
    code: string
    course_title?: string
  } | null
  mock_interview_state: 'not_scheduled' | 'booked' | 'confirmed' | 'completed' | 'passed' | 'reinterview_required' | 'cancelled'
  mock_interview?: {
    status: string
    interview_date?: string | null
    interviewer_name?: string | null
    interviewer_company?: string | null
    score?: number | null
    recommendation?: string | null
    booking_code?: string | null
  } | null
  has_active_booking: boolean
  active_booking?: AdminMockInterviewItem | null
  completed_mock?: AdminMockInterviewItem | null
  latest_evaluation?: AdminMockEvaluationItem | null
  is_eligible_for_activation: boolean
  placement_dashboard_status: 'DISABLED' | 'ELIGIBLE' | 'ENABLED' | 'SUSPENDED'
  placement_dashboard_enabled: boolean
  dashboard_status_reason?: string | null
  dashboard_status_updated_at?: string | null
  dashboard_status_updated_by?: { id: number; name: string; email: string } | null
  dashboard_enabled_at?: string | null
  dashboard_enabled_by?: { id: number; name: string; email: string } | null
  placement_eligible: boolean
}

export interface StudentEligibilityListItem {
  id: number
  name: string
  email: string
  phone?: string | null
  student_id?: string | null
  avatar?: string | null
  eligibility: StudentEligibilityData
}

export interface AdminMockStats {
  total_interviewers: number
  active_interviewers: number
  total_slots: number
  available_slots: number
  total_bookings: number
  scheduled_bookings: number
  completed_bookings: number
  cancelled_bookings: number
  no_show_bookings: number
  total_evaluations: number
  ready_for_placement: number
  needs_improvement: number
  reinterview_required: number
  total_placement_eligible: number
}
