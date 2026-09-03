import { useEffect, useState } from 'react'
import API from '../services/api'
import { useAuth } from '../context/useAuth'

interface EnquiryModalProps {
  isOpen: boolean
  onClose: () => void
  courseId?: number
  courseTitle?: string
}

interface ExistingEnquiryData {
  id: number
  name: string
  email: string
  phone?: string
  status: string
  preferred_time?: string | null
  message?: string | null
  demo_date?: string | null
  demo_time?: string | null
  created_at: string
}

const statusDisplayLabels: Record<string, string> = {
  new: 'New Lead (Under Review)',
  contacted: 'Contacted by Admissions',
  demo_scheduled: 'Demo Scheduled',
  demo_completed: 'Demo Completed',
  interested: 'Interested / Batch Assignment',
  follow_up: 'Follow-up in Progress',
  admission_confirmed: 'Admission Confirmed',
  enrolled: 'Enrolled in LMS',
  not_interested: 'Closed',
  no_response: 'Closed',
}

export default function EnquiryModal({
  isOpen,
  onClose,
  courseId,
  courseTitle,
}: EnquiryModalProps) {
  const { user } = useAuth()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [preferredTime, setPreferredTime] = useState('Morning (9 AM - 12 PM)')
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [error, setError] = useState('')
  const [existingEnquiry, setExistingEnquiry] = useState<ExistingEnquiryData | null>(null)
  const [checkingExisting, setCheckingExisting] = useState(false)

  useEffect(() => {
    if (!isOpen) return

    setSubmitted(false)
    setError('')

    // If authenticated student and courseId provided, check if active enquiry already exists
    if (user && courseId) {
      setCheckingExisting(true)
      API.get<{ has_enquiry: boolean; enquiry: ExistingEnquiryData | null }>(`/courses/${courseId}/enquiry`)
        .then((res) => {
          if (res.data.has_enquiry && res.data.enquiry) {
            setExistingEnquiry(res.data.enquiry)
          } else {
            setExistingEnquiry(null)
          }
        })
        .catch(() => {
          setExistingEnquiry(null)
        })
        .finally(() => {
          setCheckingExisting(false)
        })
    } else {
      setExistingEnquiry(null)
    }
  }, [isOpen, courseId, user])

  if (!isOpen) return null

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError('')

    try {
      const payload = user
        ? {
            course_id: courseId || undefined,
            course_title: courseTitle || undefined,
            preferred_time: preferredTime,
            message: message || undefined,
          }
        : {
            name,
            email,
            phone,
            course_id: courseId || undefined,
            course_title: courseTitle || undefined,
            preferred_time: preferredTime,
            message: message || undefined,
          }

      const res = await API.post<{ message: string; enquiry: ExistingEnquiryData; already_exists?: boolean }>(
        '/enquiries',
        payload
      )

      if (res.data.already_exists) {
        setExistingEnquiry(res.data.enquiry)
      } else {
        setSubmitted(true)
      }
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Failed to submit enquiry. Please check your details.')
    } finally {
      setLoading(false)
    }
  }

  const handleResetAndClose = () => {
    setSubmitted(false)
    setExistingEnquiry(null)
    setName('')
    setEmail('')
    setPhone('')
    setMessage('')
    setError('')
    onClose()
  }

  const displayName = user ? user.name : name
  const displayContact = user ? (user.phone || user.email) : (phone || email)

  return (
    <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-100 space-y-5 text-slate-900 max-h-[90vh] overflow-y-auto">
        {checkingExisting ? (
          <div className="py-12 text-center text-slate-500 space-y-2">
            <span className="animate-spin inline-block w-6 h-6 border-2 border-blue-600 border-t-transparent rounded-full" />
            <p className="text-xs font-semibold">Checking enquiry status...</p>
          </div>
        ) : existingEnquiry ? (
          /* DUPLICATE PROTECTION: Display existing active enquiry state */
          <div className="py-4 space-y-5">
            <div className="flex items-start justify-between gap-4">
              <div>
                <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-md">
                  Active Enquiry on Record
                </span>
                <h3 className="text-xl font-extrabold text-slate-900 mt-1">
                  Enquiry Already Submitted
                </h3>
                <p className="text-xs text-slate-500 mt-0.5">
                  You already have an active enquiry in progress for this program.
                </p>
              </div>
              <button
                type="button"
                onClick={handleResetAndClose}
                className="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 text-sm font-bold flex items-center justify-center transition"
              >
                ✕
              </button>
            </div>

            <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-3 text-xs">
              {courseTitle && (
                <div>
                  <span className="text-slate-400 text-[10px] uppercase font-bold block">Program</span>
                  <span className="font-extrabold text-slate-900 text-sm">{courseTitle}</span>
                </div>
              )}
              <div className="grid grid-cols-2 gap-3 pt-1 border-t border-slate-200/60">
                <div>
                  <span className="text-slate-400 text-[10px] uppercase font-bold block">Current Status</span>
                  <span className="inline-block mt-0.5 px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-bold text-[11px]">
                    {statusDisplayLabels[existingEnquiry.status] || existingEnquiry.status}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 text-[10px] uppercase font-bold block">Preferred Slot</span>
                  <span className="font-semibold text-slate-700">{existingEnquiry.preferred_time || 'Flexible'}</span>
                </div>
              </div>

              {existingEnquiry.demo_date && (
                <div className="pt-2 border-t border-slate-200/60">
                  <span className="text-slate-400 text-[10px] uppercase font-bold block">Scheduled Live Demo</span>
                  <span className="font-bold text-purple-700">
                    📅 {new Date(existingEnquiry.demo_date).toLocaleDateString()} {existingEnquiry.demo_time ? `at ${existingEnquiry.demo_time}` : ''}
                  </span>
                </div>
              )}

              <div className="pt-2 border-t border-slate-200/60">
                <span className="text-slate-400 text-[10px] uppercase font-bold block">Submitted On</span>
                <span className="text-slate-600">{new Date(existingEnquiry.created_at).toLocaleString()}</span>
              </div>
            </div>

            <div className="p-3 bg-blue-50 border border-blue-100 rounded-xl text-xs text-blue-800 leading-relaxed font-medium">
              💡 Our academic counseling team has your request and will contact you directly during your preferred window.
            </div>

            <button
              type="button"
              onClick={handleResetAndClose}
              className="w-full py-2.5 rounded-xl font-bold bg-slate-800 hover:bg-slate-900 text-white text-xs shadow transition"
            >
              Close
            </button>
          </div>
        ) : submitted ? (
          <div className="py-8 text-center space-y-4">
            <span className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 text-3xl font-bold">
              ✓
            </span>
            <h3 className="text-xl font-extrabold text-slate-900">Enquiry Submitted Successfully!</h3>
            <p className="text-xs text-slate-600 max-w-sm mx-auto leading-relaxed">
              Thank you, <strong className="text-slate-800">{displayName}</strong>. Our academic admissions advisor
              will contact you at <strong className="text-slate-800">{displayContact}</strong> during your
              preferred slot to arrange your demo and syllabus walkthrough.
            </p>
            {courseTitle && (
              <div className="p-3 bg-blue-50 border border-blue-100 rounded-xl text-xs font-bold text-blue-700">
                Interested Program: {courseTitle}
              </div>
            )}
            <button
              type="button"
              onClick={handleResetAndClose}
              className="px-6 py-2.5 rounded-xl font-bold bg-blue-600 hover:bg-blue-700 text-white text-xs shadow-md transition"
            >
              Done
            </button>
          </div>
        ) : (
          <>
            <div className="flex items-start justify-between gap-4">
              <div>
                <span className="text-[10px] font-extrabold uppercase px-2.5 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded-md">
                  Free Live Demo & Syllabus
                </span>
                <h3 className="text-xl font-extrabold text-slate-900 mt-1">
                  {courseTitle ? `Enquire for ${courseTitle}` : 'Talk to an Academic Advisor'}
                </h3>
                <p className="text-xs text-slate-500 mt-0.5">
                  Schedule a personalized 1-on-1 counseling session, course roadmap, and demo class.
                </p>
              </div>
              <button
                type="button"
                onClick={onClose}
                className="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 text-sm font-bold flex items-center justify-center transition"
              >
                ✕
              </button>
            </div>

            {error && (
              <div className="p-3 rounded-xl bg-red-50 text-red-700 text-xs font-bold border border-red-200">
                ⚠️ {error}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4 text-xs">
              {user ? (
                /* AUTHENTICATED STUDENT: Display verified account info as read-only */
                <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-2.5">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-md">
                      🎓 Verified Student Account
                    </span>
                    <span className="text-[10px] text-slate-400 font-semibold">Account Info (Read-only)</span>
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs text-slate-700">
                    <div>
                      <p className="text-[10px] text-slate-400 font-bold uppercase">Student Name</p>
                      <p className="font-bold text-slate-900">{user.name}</p>
                    </div>
                    <div>
                      <p className="text-[10px] text-slate-400 font-bold uppercase">Email Address</p>
                      <p className="font-bold text-slate-900 truncate">{user.email}</p>
                    </div>
                    {user.phone && (
                      <div className="sm:col-span-2">
                        <p className="text-[10px] text-slate-400 font-bold uppercase">Registered Phone</p>
                        <p className="font-bold text-slate-900">{user.phone}</p>
                      </div>
                    )}
                  </div>
                </div>
              ) : (
                /* PUBLIC VISITOR: Unchanged Name, Email, Phone inputs */
                <>
                  <div>
                    <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                      Full Name
                    </label>
                    <input
                      type="text"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="e.g. Ananya Rao"
                      className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none transition"
                      required
                    />
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                        Email Address
                      </label>
                      <input
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="ananya@example.com"
                        className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none transition"
                        required
                      />
                    </div>

                    <div>
                      <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                        Phone Number
                      </label>
                      <input
                        type="tel"
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        placeholder="+91 98765 43210"
                        className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none transition"
                        required
                      />
                    </div>
                  </div>
                </>
              )}

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Preferred Contact Window
                </label>
                <select
                  value={preferredTime}
                  onChange={(e) => setPreferredTime(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none bg-white font-semibold"
                >
                  <option value="Morning (9 AM - 12 PM)">Morning (9 AM - 12 PM)</option>
                  <option value="Afternoon (12 PM - 4 PM)">Afternoon (12 PM - 4 PM)</option>
                  <option value="Evening (4 PM - 8 PM)">Evening (4 PM - 8 PM)</option>
                  <option value="Weekend Anytime">Weekend Anytime</option>
                </select>
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase text-[10px] mb-1">
                  Questions / Career Goals (Optional)
                </label>
                <textarea
                  value={message}
                  onChange={(e) => setMessage(e.target.value)}
                  rows={3}
                  placeholder="Tell us about your background or specific questions about the curriculum..."
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-slate-900 focus:ring-2 focus:ring-blue-600 outline-none leading-relaxed"
                />
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={loading}
                  className="w-full py-3 rounded-xl font-bold bg-blue-600 hover:bg-blue-700 text-white shadow-md shadow-blue-500/20 transition disabled:opacity-50 flex items-center justify-center gap-2"
                >
                  {loading ? 'Submitting Enquiry...' : 'Book Free Live Demo & Enquire →'}
                </button>
              </div>
            </form>
          </>
        )}
      </div>
    </div>
  )
}
