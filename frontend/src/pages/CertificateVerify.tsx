import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import API from '../services/api';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';

interface VerificationResult {
  valid: boolean;
  certificate_code?: string;
  recipient_name?: string;
  course_title?: string;
  instructor?: string;
  issued_at?: string;
  message?: string;
}

export default function CertificateVerify() {
  const { code } = useParams<{ code?: string }>();
  const [inputCode, setInputCode] = useState(code || '');
  const [result, setResult] = useState<VerificationResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const verifyCode = (codeToVerify: string) => {
    if (!codeToVerify.trim()) return;

    setLoading(true);
    setError('');
    setResult(null);

    API.get<VerificationResult>(`/verify-certificate/${codeToVerify.trim()}`)
      .then((res) => setResult(res.data))
      .catch((err: unknown) => {
        const response = err as { response?: { data?: { message?: string } } };
        setError(response.response?.data?.message || 'Certificate verification failed or code does not exist.');
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (code) {
      setInputCode(code);
      verifyCode(code);
    }
  }, [code]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    verifyCode(inputCode);
  };

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <Navbar />

      <main className="flex-grow py-16 px-4 sm:px-6 lg:px-8 max-w-4xl mx-auto w-full">
        <div className="text-center mb-10">
          <span className="text-xs font-bold uppercase tracking-wider text-blue-600 bg-blue-50 px-3 py-1 rounded-full">
            Credential Authenticity
          </span>
          <h1 className="text-3xl sm:text-4xl font-extrabold text-slate-900 mt-3 mb-2">
            Verify Master In Tech Certificate
          </h1>
          <p className="text-slate-600 text-sm sm:text-base max-w-lg mx-auto">
            Validate the authentic credential ID of any graduate who completed our professional training courses.
          </p>
        </div>

        {/* Verification Form */}
        <form
          onSubmit={handleSubmit}
          className="bg-white p-6 sm:p-8 rounded-3xl ring-1 ring-slate-200 shadow-sm mb-8 flex flex-col sm:flex-row gap-3"
        >
          <input
            type="text"
            value={inputCode}
            onChange={(e) => setInputCode(e.target.value)}
            placeholder="Enter Certificate Code (e.g. MIT-2026-ABC12345)"
            className="flex-1 rounded-xl border border-slate-300 px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 outline-none uppercase font-mono"
            required
          />
          <button
            type="submit"
            disabled={loading}
            className="px-8 py-3 rounded-xl font-bold text-sm text-white bg-blue-600 hover:bg-blue-700 transition shadow disabled:opacity-50"
          >
            {loading ? 'Verifying...' : 'Verify Credential ✓'}
          </button>
        </form>

        {/* Loading State */}
        {loading && (
          <div className="text-center py-12">
            <p className="text-slate-500">Checking secure credential records...</p>
          </div>
        )}

        {/* Error / Not Found */}
        {error && !loading && (
          <div className="bg-red-50 border border-red-200 rounded-2xl p-6 text-center text-red-700">
            <p className="font-bold text-base mb-1">❌ Unverified Credential</p>
            <p className="text-sm">{error}</p>
          </div>
        )}

        {/* Valid Certificate Details */}
        {result && result.valid && !loading && (
          <div className="bg-white p-8 rounded-3xl ring-1 ring-slate-200 shadow-lg border-t-8 border-green-500 animate-in fade-in duration-300">
            <div className="flex items-center gap-3 mb-6">
              <div className="w-12 h-12 rounded-full bg-green-100 text-green-700 flex items-center justify-center text-2xl font-bold">
                ✓
              </div>
              <div>
                <span className="rounded-full bg-green-50 text-green-700 text-xs font-extrabold px-2.5 py-0.5 uppercase tracking-wider">
                  Authentic Credential Verified
                </span>
                <h2 className="text-xl font-bold text-slate-900 mt-0.5">
                  Official Master In Tech Certificate
                </h2>
              </div>
            </div>

            <div className="grid sm:grid-cols-2 gap-6 bg-slate-50 p-6 rounded-2xl border border-slate-100">
              <div>
                <p className="text-xs uppercase font-bold text-slate-400">Issued To</p>
                <p className="text-lg font-bold text-slate-900 mt-0.5">{result.recipient_name}</p>
              </div>

              <div>
                <p className="text-xs uppercase font-bold text-slate-400">Course</p>
                <p className="text-lg font-bold text-slate-900 mt-0.5">{result.course_title}</p>
              </div>

              <div>
                <p className="text-xs uppercase font-bold text-slate-400">Instructor</p>
                <p className="text-sm font-semibold text-slate-800 mt-0.5">{result.instructor}</p>
              </div>

              <div>
                <p className="text-xs uppercase font-bold text-slate-400">Date Issued</p>
                <p className="text-sm font-semibold text-slate-800 mt-0.5">
                  {result.issued_at ? new Date(result.issued_at).toLocaleDateString() : 'N/A'}
                </p>
              </div>
            </div>

            <div className="mt-6 pt-6 border-t border-slate-100 flex items-center justify-between">
              <span className="text-xs font-mono font-bold text-slate-500">
                ID: {result.certificate_code}
              </span>
              <Link
                to={`/student/certificates/${result.certificate_code}`}
                className="text-xs font-bold text-blue-600 hover:text-blue-800"
              >
                View Full Diploma →
              </Link>
            </div>
          </div>
        )}
      </main>

      <Footer />
    </div>
  );
}
