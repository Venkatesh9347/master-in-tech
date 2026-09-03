import { useState, useEffect, useRef } from 'react';
import { useAuth } from '../../context/useAuth';
import type { User, GoogleAuthPendingSession } from '../../context/auth-context';

interface OtpVerificationModalProps {
  isOpen: boolean;
  onClose: () => void;
  pendingSession: GoogleAuthPendingSession | null;
  onSuccess: (user: User) => void;
  onSessionUpdate: (newSession: GoogleAuthPendingSession) => void;
}

export default function OtpVerificationModal({
  isOpen,
  onClose,
  pendingSession,
  onSuccess,
  onSessionUpdate,
}: OtpVerificationModalProps) {
  const { verifyOtp, resendOtp } = useAuth();

  const [otpDigits, setOtpDigits] = useState<string[]>(['', '', '', '', '', '']);
  const [secondsRemaining, setSecondsRemaining] = useState<number>(30);
  const [resendCooldown, setResendCooldown] = useState<number>(30);
  const [error, setError] = useState<string>('');
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [resending, setResending] = useState<boolean>(false);
  const [resendSuccess, setResendSuccess] = useState<string>('');

  const inputRefs = useRef<(HTMLInputElement | null)[]>([]);

  // Reset timer and inputs when a new pending session opens or updates
  useEffect(() => {
    if (isOpen && pendingSession) {
      setOtpDigits(['', '', '', '', '', '']);
      setSecondsRemaining(pendingSession.expires_in || 30);
      setResendCooldown(pendingSession.resend_cooldown || 30);
      setError('');
      setResendSuccess('');

      const focusTimer = setTimeout(() => {
        inputRefs.current[0]?.focus();
      }, 150);

      return () => clearTimeout(focusTimer);
    }
  }, [isOpen, pendingSession]);

  // Active 1-second interval countdown for 30s OTP validity & resend cooldown
  useEffect(() => {
    if (!isOpen || !pendingSession) return;

    const interval = setInterval(() => {
      setSecondsRemaining((prev) => (prev > 0 ? prev - 1 : 0));
      setResendCooldown((prev) => (prev > 0 ? prev - 1 : 0));
    }, 1000);

    return () => clearInterval(interval);
  }, [isOpen, pendingSession]);

  if (!isOpen || !pendingSession) return null;

  const handleDigitChange = (index: number, value: string) => {
    // Handle paste of full 6-digit code
    if (value.length > 1) {
      const sanitized = value.replace(/\D/g, '').slice(0, 6);
      if (sanitized) {
        const nextDigits = [...otpDigits];
        for (let i = 0; i < 6; i++) {
          nextDigits[i] = sanitized[i] || '';
        }
        setOtpDigits(nextDigits);
        const nextFocus = Math.min(sanitized.length, 5);
        inputRefs.current[nextFocus]?.focus();
        if (sanitized.length === 6) {
          executeVerification(sanitized);
        }
      }
      return;
    }

    const sanitizedChar = value.replace(/\D/g, '');
    const newDigits = [...otpDigits];
    newDigits[index] = sanitizedChar;
    setOtpDigits(newDigits);
    setError('');

    // Advance focus to next box
    if (sanitizedChar && index < 5) {
      inputRefs.current[index + 1]?.focus();
    }

    // Auto submit if all 6 digits filled
    if (sanitizedChar && index === 5) {
      const fullOtp = newDigits.join('');
      if (fullOtp.length === 6) {
        executeVerification(fullOtp);
      }
    }
  };

  const handleKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !otpDigits[index] && index > 0) {
      inputRefs.current[index - 1]?.focus();
    }
  };

  const executeVerification = async (codeToVerify?: string) => {
    const code = codeToVerify || otpDigits.join('');
    if (code.length !== 6) {
      setError('Please enter the complete 6-digit verification code.');
      return;
    }

    if (secondsRemaining <= 0) {
      setError('This verification code has expired (30 seconds limit). Please request a new code.');
      return;
    }

    setError('');
    setSubmitting(true);

    try {
      const user = await verifyOtp(pendingSession.temp_token, code);
      onSuccess(user);
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string; errors?: { otp?: string[] } } } };
      const errorMessage =
        response.response?.data?.errors?.otp?.[0] ||
        response.response?.data?.message ||
        'Verification failed. Please check the code and try again.';
      setError(errorMessage);
    } finally {
      setSubmitting(false);
    }
  };

  const handleResend = async () => {
    if (resendCooldown > 0 || resending) return;

    setResending(true);
    setError('');
    setResendSuccess('');

    try {
      const updated = await resendOtp(pendingSession.temp_token);
      onSessionUpdate(updated);
      setOtpDigits(['', '', '', '', '', '']);
      setSecondsRemaining(updated.expires_in || 30);
      setResendCooldown(updated.resend_cooldown || 30);
      setResendSuccess('New 30-second verification code sent to your email.');
      inputRefs.current[0]?.focus();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string; errors?: { resend?: string[]; temp_token?: string[] } } } };
      const errorMessage =
        response.response?.data?.errors?.resend?.[0] ||
        response.response?.data?.errors?.temp_token?.[0] ||
        response.response?.data?.message ||
        'Unable to resend verification code. Please sign in again.';
      setError(errorMessage);
    } finally {
      setResending(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/75 backdrop-blur-sm animate-in fade-in duration-200"
      role="dialog"
      aria-modal="true"
      aria-labelledby="otp-modal-title"
    >
      <div className="w-full max-w-md bg-white rounded-3xl shadow-2xl border border-slate-100 p-8 sm:p-10 relative text-center">
        {/* Header Icon */}
        <div className="w-16 h-16 rounded-2xl bg-blue-50 border border-blue-100 flex items-center justify-center mx-auto mb-4 text-3xl shadow-inner">
          🔐
        </div>

        {/* Title */}
        <h2 id="otp-modal-title" className="text-2xl font-extrabold text-slate-900 tracking-tight">
          MasterInTech OTP Verification
        </h2>
        <p className="text-xs sm:text-sm text-slate-500 mt-2">
          Enter the 6-digit security code sent to{' '}
          <strong className="text-slate-800 font-semibold">
            {pendingSession.masked_phone || pendingSession.masked_email || pendingSession.email || pendingSession.phone || 'your registered device'}
          </strong>
        </p>

        {/* Live 30-Second Countdown Timer Badge */}
        <div className="mt-4 mb-6">
          <div
            className={`inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-bold transition-all ${
              secondsRemaining > 10
                ? 'bg-blue-50 text-blue-700 border border-blue-200'
                : secondsRemaining > 0
                ? 'bg-amber-50 text-amber-700 border border-amber-200 animate-pulse'
                : 'bg-red-50 text-red-700 border border-red-200'
            }`}
          >
            <span>⏱️</span>
            {secondsRemaining > 0 ? (
              <span>Expires in {secondsRemaining}s</span>
            ) : (
              <span>Code Expired (30s reached)</span>
            )}
          </div>
        </div>

        {/* Error Alert */}
        {error && (
          <div className="mb-5 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs font-medium flex items-center justify-center gap-2 text-left">
            <span>⚠️</span>
            <span>{error}</span>
          </div>
        )}

        {/* Resend Success Alert */}
        {resendSuccess && (
          <div className="mb-5 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-medium flex items-center justify-center gap-2 text-left">
            <span>✓</span>
            <span>{resendSuccess}</span>
          </div>
        )}

        {/* 6-Digit Box Input */}
        <div className="flex justify-center gap-2 sm:gap-3 mb-6">
          {otpDigits.map((digit, idx) => (
            <input
              key={idx}
              ref={(el) => {
                inputRefs.current[idx] = el;
              }}
              type="text"
              inputMode="numeric"
              maxLength={1}
              value={digit}
              disabled={submitting}
              onChange={(e) => handleDigitChange(idx, e.target.value)}
              onKeyDown={(e) => handleKeyDown(idx, e)}
              className={`w-11 h-13 sm:w-12 sm:h-14 text-center text-xl sm:text-2xl font-black rounded-xl border transition outline-none ${
                digit
                  ? 'border-blue-600 bg-blue-50/40 text-blue-900 ring-2 ring-blue-600/20'
                  : 'border-slate-300 bg-slate-50 text-slate-900 focus:border-blue-600 focus:bg-white focus:ring-2 focus:ring-blue-600/20'
              } ${secondsRemaining === 0 ? 'border-red-300 bg-red-50/20' : ''}`}
            />
          ))}
        </div>

        {/* Verify Submit Button */}
        <button
          type="button"
          onClick={() => executeVerification()}
          disabled={submitting || secondsRemaining === 0 || otpDigits.join('').length !== 6}
          className="w-full py-3 px-4 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 active:bg-blue-800 shadow-md shadow-blue-500/20 transition disabled:opacity-50 flex items-center justify-center gap-2 text-sm"
        >
          {submitting ? (
            <>
              <span className="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full" />
              <span>Verifying OTP...</span>
            </>
          ) : (
            <span>Verify & Enter Student Portal →</span>
          )}
        </button>

        {/* Resend & Cooldown Section */}
        <div className="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
          <button
            type="button"
            onClick={handleResend}
            disabled={resendCooldown > 0 || resending}
            className={`font-semibold transition ${
              resendCooldown > 0
                ? 'text-slate-400 cursor-not-allowed'
                : 'text-blue-600 hover:text-blue-700 hover:underline'
            }`}
          >
            {resending
              ? 'Sending new code...'
              : resendCooldown > 0
              ? `Resend available in ${resendCooldown}s`
              : 'Resend 30-second OTP'}
          </button>

          <button
            type="button"
            onClick={onClose}
            className="text-slate-500 hover:text-slate-700 transition font-medium"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}
