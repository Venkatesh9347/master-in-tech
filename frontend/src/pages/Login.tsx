import { useState, useEffect, useRef } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/useAuth';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import GoogleAuthButton from '../components/auth/GoogleAuthButton';
import OtpVerificationModal from '../components/auth/OtpVerificationModal';
import PublicAccessGateModal from '../components/PublicAccessGateModal';
import type { User, GoogleAuthPendingSession } from '../context/auth-context';

export default function Login() {
  const navigate = useNavigate();
  const location = useLocation();
  const { login, initiateGoogleAuth, initiateMobileAuth } = useAuth();

  const [studentAuthMode, setStudentAuthMode] = useState<'google' | 'mobile'>('google');
  const [mobileNumber, setMobileNumber] = useState('');
  const [mobileLoading, setMobileLoading] = useState(false);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [enquiryOpen, setEnquiryOpen] = useState(false);

  // Single Active Session Revocation Notice
  const [sessionExpiredNotice, setSessionExpiredNotice] = useState<string | null>(() => {
    const notice = sessionStorage.getItem('session_revoked_notice');
    if (notice) {
      sessionStorage.removeItem('session_revoked_notice');
      return notice;
    }
    const params = new URLSearchParams(window.location.search);
    if (params.get('session_expired') === '1') {
      return 'Your session has expired because your account was signed in on another device.';
    }
    return null;
  });

  // Phase 1: Student Google/Mobile Auth + 30s OTP State
  const [pendingOtpSession, setPendingOtpSession] = useState<GoogleAuthPendingSession | null>(null);
  const processedCodeRef = useRef<string | null>(null);

  // Detect and handle Google OAuth redirect return callback (?code=... or ?error=...)
  useEffect(() => {
    const params = new URLSearchParams(location.search);
    const code = params.get('code');
    const authError = params.get('error');

    if (authError) {
      setError('Google authentication was cancelled or access was denied.');
      navigate(location.pathname, { replace: true });
      return;
    }

    if (code && processedCodeRef.current !== code) {
      processedCodeRef.current = code;
      setLoading(true);
      setError('');

      navigate(location.pathname, { replace: true });

      initiateGoogleAuth({
        code,
        redirect_uri: `${window.location.origin}/login`,
      })
        .then((session) => {
          setPendingOtpSession(session);
        })
        .catch((err: unknown) => {
          const response = err as { response?: { data?: { message?: string; errors?: { credential?: string[] } } } };
          setError(
            response.response?.data?.errors?.credential?.[0] ||
            response.response?.data?.message ||
            'Your Google account is not registered for student access. Please contact MasterInTech.'
          );
        })
        .finally(() => {
          setLoading(false);
        });
    }
  }, [location.search, location.pathname, navigate, initiateGoogleAuth]);

  const handlePostAuthRedirect = (loggedInUser: User) => {
    const from = (location.state as { from?: { pathname: string } } | null)?.from?.pathname;
    if (from) {
      if (from.startsWith('/admin') && (loggedInUser.role === 'admin' || loggedInUser.role === 'super_admin')) {
        navigate(from, { replace: true });
        return;
      }
      if (from.startsWith('/tutor') && (loggedInUser.role === 'tutor' || loggedInUser.role === 'faculty')) {
        navigate(from, { replace: true });
        return;
      }
      if (from.startsWith('/student') && (loggedInUser.role === 'student' || !loggedInUser.role)) {
        navigate(from, { replace: true });
        return;
      }
    }

    if (loggedInUser.role === 'admin' || loggedInUser.role === 'super_admin') {
      navigate('/admin', { replace: true });
    } else if (loggedInUser.role === 'tutor' || loggedInUser.role === 'faculty') {
      navigate('/tutor', { replace: true });
    } else if (loggedInUser.role === 'counsellor') {
      navigate('/admin/crm', { replace: true });
    } else if (loggedInUser.role === 'company' || loggedInUser.role === 'recruiter') {
      navigate('/company', { replace: true });
    } else {
      navigate('/student', { replace: true });
    }
  };

  const handleGoogleSuccess = (session: GoogleAuthPendingSession) => {
    setError('');
    setPendingOtpSession(session);
  };

  const handleMobileSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    if (!mobileNumber.trim()) {
      setError('Please enter your registered mobile number.');
      return;
    }

    setMobileLoading(true);
    try {
      const session = await initiateMobileAuth(mobileNumber.trim());
      setPendingOtpSession(session);
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string; errors?: { phone?: string[] } } } };
      setError(
        response.response?.data?.errors?.phone?.[0] ||
        response.response?.data?.message ||
        'Your mobile number is not registered for student access. Please contact MasterInTech.'
      );
    } finally {
      setMobileLoading(false);
    }
  };

  const handleOtpVerified = (verifiedUser: User) => {
    setPendingOtpSession(null);
    handlePostAuthRedirect(verifiedUser);
  };

  const handlePasswordLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const loggedInUser = await login(email.trim(), password);
      handlePostAuthRedirect(loggedInUser);
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Unable to log in with those credentials.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <Navbar />

      <main className="flex-grow flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div className="w-full max-w-md">
          <div className="bg-white rounded-3xl p-8 sm:p-10 shadow-xl border border-slate-100">
            <div className="text-center mb-8">
              <span className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 text-2xl font-bold mb-3 shadow-inner">
                🎓
              </span>
              <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                Student Sign In
              </h1>
              <p className="text-xs sm:text-sm text-slate-500 mt-2">
                Access your assigned curriculum, classroom, and capstones
              </p>
            </div>

            {sessionExpiredNotice && (
              <div className="mb-6 p-4 rounded-2xl bg-amber-50 border border-amber-300 text-amber-900 text-xs sm:text-sm font-medium flex items-start justify-between gap-3 shadow-xs">
                <div className="flex items-start gap-3">
                  <span className="text-xl shrink-0">⚠️</span>
                  <div>
                    <h3 className="font-bold text-amber-950 text-sm">Session Expired</h3>
                    <p className="mt-0.5 text-xs text-amber-800 leading-relaxed">{sessionExpiredNotice}</p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setSessionExpiredNotice(null)}
                  className="text-amber-800 hover:text-amber-950 text-xs font-bold px-1"
                  aria-label="Dismiss notice"
                >
                  ✕
                </button>
              </div>
            )}

            {error && (
              <div className="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs sm:text-sm font-medium flex items-start gap-2">
                <span className="text-base">⚠️</span>
                <span>{error}</span>
              </div>
            )}

            {/* Dual Student Authentication: Google & Mobile */}
            <div className="mb-6">
              <div className="flex rounded-xl bg-slate-100 p-1 mb-4">
                <button
                  type="button"
                  onClick={() => {
                    setStudentAuthMode('google');
                    setError('');
                  }}
                  className={`flex-1 py-2 text-xs font-bold rounded-lg transition ${
                    studentAuthMode === 'google'
                      ? 'bg-white text-slate-900 shadow-xs'
                      : 'text-slate-500 hover:text-slate-800'
                  }`}
                >
                  Continue with Google
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setStudentAuthMode('mobile');
                    setError('');
                  }}
                  className={`flex-1 py-2 text-xs font-bold rounded-lg transition ${
                    studentAuthMode === 'mobile'
                      ? 'bg-white text-slate-900 shadow-xs'
                      : 'text-slate-500 hover:text-slate-800'
                  }`}
                >
                  Continue with Mobile
                </button>
              </div>

              {studentAuthMode === 'google' ? (
                <div>
                  <GoogleAuthButton
                    onSuccess={handleGoogleSuccess}
                    onError={(err) => setError(err)}
                  />
                  <p className="text-[11px] text-slate-500 text-center mt-2.5 font-medium">
                    🔒 Requires approved Google account + 30s MasterInTech OTP
                  </p>
                </div>
              ) : (
                <form onSubmit={handleMobileSubmit} className="space-y-3">
                  <div>
                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                      Registered Mobile Number
                    </label>
                    <input
                      type="tel"
                      value={mobileNumber}
                      onChange={(e) => setMobileNumber(e.target.value)}
                      placeholder="+91 98765 43210"
                      className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-slate-900 placeholder:text-slate-400 focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none transition text-sm"
                      required
                    />
                  </div>
                  <button
                    type="submit"
                    disabled={mobileLoading}
                    className="w-full py-2.5 px-4 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-500 transition shadow-sm disabled:opacity-50 text-xs flex items-center justify-center gap-2"
                  >
                    {mobileLoading ? (
                      <>
                        <span className="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full" />
                        <span>Verifying mobile...</span>
                      </>
                    ) : (
                      <span>Get 30-Second OTP Code →</span>
                    )}
                  </button>
                  <p className="text-[11px] text-slate-500 text-center mt-1 font-medium">
                    📲 SMS/Email code sent to registered contact details
                  </p>
                </form>
              )}
            </div>

            <div className="relative flex py-2 items-center">
              <div className="flex-grow border-t border-slate-200"></div>
              <span className="flex-shrink mx-3 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                or Instructor / Staff
              </span>
              <div className="flex-grow border-t border-slate-200"></div>
            </div>

            {/* Standard Password Login Form for Instructors & Admins */}
            <form className="space-y-4 mt-4" onSubmit={handlePasswordLogin}>
              <div>
                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                  Email Address
                </label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="faculty@masterintech.com"
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-slate-900 placeholder:text-slate-400 focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none transition text-sm"
                  required
                />
              </div>

              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Password
                  </label>
                  <Link
                    to="/forgot-password"
                    className="text-xs font-semibold text-blue-600 hover:text-blue-700 transition"
                  >
                    Forgot password?
                  </Link>
                </div>
                <div className="relative">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full pl-4 pr-11 py-2.5 rounded-xl border border-slate-300 text-slate-900 placeholder:text-slate-400 focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none transition text-sm"
                    required
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-0 top-0 h-full px-3.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none focus:text-blue-600 transition"
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                  >
                    {showPassword ? (
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                      </svg>
                    ) : (
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                    )}
                  </button>
                </div>
              </div>

              <button
                type="submit"
                disabled={loading}
                className="w-full py-2.5 px-4 rounded-xl font-bold text-white bg-slate-800 hover:bg-slate-900 active:bg-slate-950 shadow-md transition disabled:opacity-50 flex items-center justify-center gap-2 text-sm"
              >
                {loading ? (
                  <>
                    <span className="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full" />
                    <span>Signing in...</span>
                  </>
                ) : (
                  <span>Staff / Instructor Sign In →</span>
                )}
              </button>
            </form>

            <div className="mt-8 pt-6 border-t border-slate-100 text-center space-y-2">
              <p className="text-xs text-slate-600">
                New student looking to join MasterInTech?
              </p>
              <button
                type="button"
                onClick={() => setEnquiryOpen(true)}
                className="inline-block px-4 py-2 rounded-xl text-xs font-bold text-blue-600 hover:text-blue-700 bg-blue-50 hover:bg-blue-100/80 transition"
              >
                Submit Course Enquiry →
              </button>
            </div>
          </div>
        </div>
      </main>

      {/* Mandatory MasterInTech 30-Second OTP Verification Modal */}
      <OtpVerificationModal
        isOpen={Boolean(pendingOtpSession)}
        onClose={() => setPendingOtpSession(null)}
        pendingSession={pendingOtpSession}
        onSuccess={handleOtpVerified}
        onSessionUpdate={(updatedSession) => setPendingOtpSession(updatedSession)}
      />

      {/* Enquiry Modal */}
      <PublicAccessGateModal
        isOpen={enquiryOpen}
        onClose={() => setEnquiryOpen(false)}
      />

      <Footer />
    </div>
  );
}