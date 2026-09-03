import { useState } from 'react';
import { Link } from 'react-router-dom';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import PublicAccessGateModal from '../components/PublicAccessGateModal';

export default function Register() {
  const [enquiryOpen, setEnquiryOpen] = useState(false);

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col selection:bg-blue-600 selection:text-white">
      <Navbar />

      <main className="flex-grow flex items-center justify-center py-16 px-4 sm:px-6 lg:px-8">
        <div className="w-full max-w-lg">
          <div className="bg-white rounded-3xl p-8 sm:p-10 shadow-xl border border-slate-100 text-center space-y-6">
            {/* Icon Header */}
            <div className="w-16 h-16 rounded-2xl bg-blue-50 border border-blue-100 text-blue-600 text-3xl font-bold flex items-center justify-center mx-auto shadow-inner">
              🎓
            </div>

            <div className="space-y-2">
              <span className="inline-block px-3 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200/60 text-[11px] font-extrabold uppercase tracking-wider">
                Admissions & Enrollment
              </span>
              <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                Student Account Creation
              </h1>
              <p className="text-xs sm:text-sm text-slate-600 max-w-md mx-auto leading-relaxed">
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
        </div>
      </main>

      {/* Enquiry Modal */}
      <PublicAccessGateModal
        isOpen={enquiryOpen}
        onClose={() => setEnquiryOpen(false)}
      />

      <Footer />
    </div>
  );
}