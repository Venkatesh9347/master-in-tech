import { useState } from 'react'
import API from '../services/api'
import Navbar from '../components/Navbar'
import Footer from '../components/Footer'

export default function Contact() {
  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    subject: '',
    message: '',
  })
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [submitError, setSubmitError] = useState('')

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setForm({ ...form, [e.target.name]: e.target.value })
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setIsSubmitting(true)
    setSubmitError('')
    // Persist as a CRM enquiry so the message actually reaches the team.
    // The subject is prefixed transparently — never silently dropped.
    const message = form.subject.trim()
      ? `[${form.subject.trim()}] ${form.message.trim()}`
      : form.message.trim()
    API.post('/enquiries', {
      name: form.name.trim(),
      email: form.email.trim(),
      phone: form.phone.trim(),
      message,
    })
      .then(() => {
        setSubmitted(true)
      })
      .catch((err: unknown) => {
        const response = (err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } })?.response
        const firstFieldError = response?.data?.errors
          ? Object.values(response.data.errors).flat()[0]
          : undefined
        setSubmitError(
          firstFieldError ||
            response?.data?.message ||
            (response?.status === undefined
              ? 'Network error. Please check your connection and try again.'
              : 'Unable to send your message right now. Please try again later.')
        )
      })
      .finally(() => {
        setIsSubmitting(false)
      })
  }

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-500 selection:text-white">
      <Navbar />

      {/* Header */}
      <section className="bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 text-white py-16 border-b border-slate-800 text-center">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          <span className="text-xs font-extrabold uppercase tracking-widest text-blue-400">
            Student Support & Advisory
          </span>
          <h1 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-2">
            Get in Touch with Our Team
          </h1>
          <p className="text-sm sm:text-base text-slate-300 mt-3 max-w-2xl mx-auto font-normal leading-relaxed">
            Have questions regarding curriculum tracks, institutional training, or course enrollment? We are here to help.
          </p>
        </div>
      </section>

      <main className="flex-grow py-16 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 w-full">
        <div className="grid gap-10 lg:grid-cols-2">
          {/* Contact Details Card */}
          <div className="space-y-6">
            <div>
              <h2 className="text-2xl font-black text-slate-900">Reach Us Directly</h2>
              <p className="text-xs text-slate-500 mt-1">Our advisory desk typically responds within 2 business hours.</p>
            </div>

            <div className="space-y-4">
              <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-start gap-4">
                <span className="text-2xl p-2.5 bg-blue-50 rounded-xl">📍</span>
                <div>
                  <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Headquarters</h3>
                  <p className="text-sm font-bold text-slate-800 mt-0.5">Bangalore, Karnataka, India</p>
                </div>
              </div>

              <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-start gap-4">
                <span className="text-2xl p-2.5 bg-blue-50 rounded-xl">✉️</span>
                <div>
                  <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Email Inquiries</h3>
                  <p className="text-sm font-bold text-slate-800 mt-0.5">admissions@masterintech.com</p>
                </div>
              </div>

              <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-start gap-4">
                <span className="text-2xl p-2.5 bg-blue-50 rounded-xl">📞</span>
                <div>
                  <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Student Helpline</h3>
                  <p className="text-sm font-bold text-slate-800 mt-0.5">+91 98765 43210</p>
                </div>
              </div>

              <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-start gap-4">
                <span className="text-2xl p-2.5 bg-blue-50 rounded-xl">🕒</span>
                <div>
                  <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Advisory Hours</h3>
                  <p className="text-sm font-bold text-slate-800 mt-0.5">Monday – Saturday: 9:00 AM – 7:00 PM IST</p>
                </div>
              </div>
            </div>
          </div>

          {/* Contact Form Card */}
          <div className="bg-white rounded-3xl p-8 border border-slate-200/80 shadow-xs">
            {submitted ? (
              <div className="text-center py-12">
                <div className="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-2xl mx-auto mb-4 font-bold shadow-xs">
                  ✓
                </div>
                <h3 className="text-xl font-bold text-slate-900 mb-2">
                  Message Sent Successfully!
                </h3>
                <p className="text-xs text-slate-500 max-w-xs mx-auto mb-6">
                  Thank you for reaching out. A career advisor will review your message and contact you shortly.
                </p>
                <button
                  type="button"
                  onClick={() => {
                    setSubmitted(false)
                    setSubmitError('')
                    setForm({ name: '', email: '', phone: '', subject: '', message: '' })
                  }}
                  className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700"
                >
                  Send Another Inquiry
                </button>
              </div>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-4 text-xs">
                <h3 className="text-lg font-bold text-slate-900 mb-4">Send a Message</h3>

                <div>
                  <label className="block text-slate-700 font-bold uppercase text-[10px] mb-1">
                    Your Name
                  </label>
                  <input
                    type="text"
                    name="name"
                    value={form.name}
                    onChange={handleChange}
                    required
                    placeholder="e.g. John Doe"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-slate-700 font-bold uppercase text-[10px] mb-1">
                    Email Address
                  </label>
                  <input
                    type="email"
                    name="email"
                    value={form.email}
                    onChange={handleChange}
                    required
                    placeholder="you@example.com"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-slate-700 font-bold uppercase text-[10px] mb-1">
                    Mobile Number
                  </label>
                  <input
                    type="tel"
                    name="phone"
                    value={form.phone}
                    onChange={handleChange}
                    required
                    placeholder="e.g. 9876543210"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-slate-700 font-bold uppercase text-[10px] mb-1">
                    Subject / Program of Interest
                  </label>
                  <input
                    type="text"
                    name="subject"
                    value={form.subject}
                    onChange={handleChange}
                    required
                    placeholder="e.g. AI & ML Program Inquiry"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-slate-700 font-bold uppercase text-[10px] mb-1">
                    Message Details
                  </label>
                  <textarea
                    name="message"
                    value={form.message}
                    onChange={handleChange}
                    required
                    rows={4}
                    placeholder="Tell us how we can help..."
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-600 outline-none"
                  />
                </div>

                {submitError && (
                  <p className="text-[11px] text-red-700 bg-red-50 border border-red-200 rounded-xl px-3 py-2">
                    {submitError}
                  </p>
                )}

                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full py-3 px-4 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 transition shadow-sm disabled:opacity-50"
                >
                  {isSubmitting ? 'Submitting Message...' : 'Submit Inquiry →'}
                </button>
              </form>
            )}
          </div>
        </div>
      </main>

      <Footer />
    </div>
  )
}
