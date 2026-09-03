import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import API from '../services/api';

interface CertificateData {
  certificate_code: string;
  issued_at: string;
  user?: {
    name: string;
    email: string;
  };
  course?: {
    title: string;
    instructor: string;
  };
}

export default function CertificateView() {
  const { code } = useParams<{ code: string }>();
  const [cert, setCert] = useState<CertificateData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!code) return;
    API.get<CertificateData>(`/certificates/${code}`)
      .then((res) => setCert(res.data))
      .catch(() => setError('Unable to find this certificate.'))
      .finally(() => setLoading(false));
  }, [code]);

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-900 text-white flex items-center justify-center p-6">
        <p className="text-slate-400">Loading Certificate of Completion...</p>
      </div>
    );
  }

  if (error || !cert) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div className="bg-white p-8 rounded-3xl ring-1 ring-slate-200 text-center max-w-md">
          <p className="text-red-600 font-bold mb-4">{error || 'Certificate not found'}</p>
          <Link to="/student" className="text-blue-600 font-bold hover:underline">
            ← Return to Dashboard
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-900 py-12 px-4 sm:px-6 flex flex-col items-center justify-center">
      {/* Top action bar */}
      <div className="w-full max-w-4xl flex items-center justify-between mb-8 print:hidden">
        <Link
          to="/student"
          className="text-xs font-bold text-slate-400 hover:text-white transition flex items-center gap-1 bg-slate-800 px-4 py-2 rounded-xl"
        >
          <span>←</span>
          <span>Back to Dashboard</span>
        </Link>

        <button
          type="button"
          onClick={() => window.print()}
          className="px-6 py-2.5 rounded-xl font-bold text-xs text-slate-900 bg-amber-400 hover:bg-amber-300 transition shadow-lg shadow-amber-400/20 flex items-center gap-2"
        >
          <span>🖨️</span>
          <span>Print / Save PDF</span>
        </button>
      </div>

      {/* Certificate Frame */}
      <div className="w-full max-w-4xl bg-white text-slate-900 rounded-3xl p-10 sm:p-16 shadow-2xl border-8 border-double border-amber-500/40 relative overflow-hidden print:border-amber-600 print:shadow-none">
        {/* Background Watermark */}
        <div className="absolute inset-0 flex items-center justify-center opacity-[0.03] pointer-events-none text-9xl font-black select-none">
          MASTER IN TECH
        </div>

        {/* Certificate Header */}
        <div className="text-center relative z-10">
          <div className="inline-flex items-center gap-2 text-amber-600 font-extrabold uppercase tracking-widest text-xs mb-2">
            <span>★</span>
            <span>Official Credential of Completion</span>
            <span>★</span>
          </div>

          <h1 className="text-4xl sm:text-5xl font-serif font-black tracking-tight text-slate-950 mt-2 mb-4">
            Certificate of Completion
          </h1>

          <p className="text-slate-500 text-sm font-sans uppercase tracking-widest">
            This is proudly presented to
          </p>

          {/* Student Name */}
          <div className="my-8 py-2 border-b-2 border-slate-300 inline-block px-12 min-w-[320px]">
            <h2 className="text-3xl sm:text-4xl font-serif font-bold text-blue-900">
              {cert.user?.name || 'Student'}
            </h2>
          </div>

          <p className="text-slate-600 max-w-xl mx-auto text-base leading-relaxed mb-6 font-sans">
            for successfully completing all required modules, projects, quizzes, and practical assignments for the course:
          </p>

          {/* Course Title */}
          <h3 className="text-2xl sm:text-3xl font-extrabold text-slate-900 mb-10 font-sans">
            {cert.course?.title}
          </h3>

          {/* Footer details: Instructor + Date + Verification Code */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-8 pt-8 border-t border-slate-200 mt-10 text-left font-sans">
            <div>
              <p className="text-xs uppercase font-bold text-slate-400">Instructor</p>
              <p className="text-base font-bold text-slate-900 mt-1 font-serif">
                {cert.course?.instructor}
              </p>
              <p className="text-xs text-slate-400">Master In Tech Faculty</p>
            </div>

            <div className="sm:text-center">
              <p className="text-xs uppercase font-bold text-slate-400">Issue Date</p>
              <p className="text-base font-bold text-slate-900 mt-1">
                {new Date(cert.issued_at).toLocaleDateString(undefined, {
                  year: 'numeric',
                  month: 'long',
                  day: 'numeric',
                })}
              </p>
              <p className="text-xs text-slate-400">Permanent Record</p>
            </div>

            <div className="sm:text-right">
              <p className="text-xs uppercase font-bold text-slate-400">Credential ID</p>
              <p className="text-base font-mono font-bold text-blue-700 mt-1">
                {cert.certificate_code}
              </p>
              <Link
                to={`/verify-certificate/${cert.certificate_code}`}
                className="text-xs text-slate-400 hover:text-blue-600 block mt-0.5 print:text-slate-500"
              >
                verify on masterintech.com
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
