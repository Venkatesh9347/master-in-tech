import { useState } from 'react';
import { Link } from 'react-router-dom';
import Footer from '../components/Footer';
import AuthShell from '../components/motion/AuthShell';
import PublicAccessGateModal from '../components/PublicAccessGateModal';

export default function Register() {
  const [enquiryOpen, setEnquiryOpen] = useState(false);

  return (
    <>
      <AuthShell
      title="Student Account Creation"
      subtitle="Accounts are provisioned by our academic administration team after 1-on-1 career counselling."
      segments={56}
    >
        <div className="space-y-6 text-center">
            {/* Icon Header */}
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl border border-blue-100 bg-blue-50 text-3xl font-bold text-blue-600 shadow-inner">
              🎓
            </div>

            <div className="space-y-2">
              <span className="inline-block rounded-full border border-blue-200/60 bg-blue-50 px-3 py-1 text-[11px] font-extrabold uppercase tracking-wider text-blue-700">
                Admissions &amp; Enrollment
              </span>
              <p className="mx-auto max-w-md text-xs leading-relaxed text-slate-600 sm:text-sm">
                To guarantee curriculum fit and personalized mentorship, student accounts at MasterInTech are provisioned by our academic administration team following 1-on-1 career counselling.
              </p>
            </div>

            {/* How it works steps */}
            <div className="bg-slate-50 rounded-2xl p-5 border border-slate-200/70 text-left space-y-3">
              <h2 className="text-xs font-bold text-slate-900 uppercase tracking-wider">
                How to get started:
              </h2>
              <div className="space-y-2 text-xs text-slate-700">
                <div className="flex items-start gap-2.5">
                  <span className="w-5 h-5 rounded-full bg-blue-600 text-white font-black text-[10px] flex items-center justify-center shrink-0 mt-0.5">1</span>
                  <span><strong>Submit an Enquiry:</strong> Select your interested technology domain and leave your contact details.</span>
                </div>
                <div className="flex items-start gap-2.5">
                  <span className="w-5 h-5 rounded-full bg-blue-600 text-white font-black text-[10px] flex items-center justify-center shrink-0 mt-0.5">2</span>
                  <span><strong>Faculty Counselling:</strong> Our admissions advisor will contact you to review syllabus and prerequisites.</span>
                </div>
                <div className="flex items-start gap-2.5">
                  <span className="w-5 h-5 rounded-full bg-blue-600 text-white font-black text-[10px] flex items-center justify-center shrink-0 mt-0.5">3</span>
                  <span><strong>Account Activation:</strong> Your student account will be activated with Google & Mobile sign-in access.</span>
                </div>
              </div>
            </div>

            {/* Action Buttons */}
            <div className="space-y-3 pt-2">
              <button
                type="button"
                onClick={() => setEnquiryOpen(true)}
                className="w-full py-3.5 px-4 rounded-xl font-bold text-xs sm:text-sm text-white bg-blue-600 hover:bg-blue-500 transition shadow-md shadow-blue-500/20 flex items-center justify-center gap-2"
              >
                <span>💬</span> Submit Course Enquiry Now →
              </button>

              <Link
                to="/login"
                className="w-full py-2.5 px-4 rounded-xl font-bold text-xs text-slate-700 bg-slate-100 hover:bg-slate-200 transition flex items-center justify-center gap-1.5"
              >
                Already have an approved student account? Sign In
              </Link>
            </div>
          </div>
      </AuthShell>

      {/* Enquiry Modal */}
      <PublicAccessGateModal
        isOpen={enquiryOpen}
        onClose={() => setEnquiryOpen(false)}
      />

      <Footer />
    </>
  );
}