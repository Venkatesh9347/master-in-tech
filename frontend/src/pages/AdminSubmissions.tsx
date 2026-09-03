import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import API from '../services/api';

interface SubmissionItem {
  id: number;
  user_id: number;
  assignment_id: number;
  course_id: number;
  submission_text: string | null;
  file_url: string | null;
  submitted_at: string | null;
  score: number | null;
  feedback: string | null;
  status: 'submitted' | 'graded' | 'returned';
  user?: {
    id: number;
    name: string;
    email: string;
  };
  assignment?: {
    id: number;
    title: string;
    max_marks: number;
  };
  course?: {
    id: number;
    title: string;
  };
}

export default function AdminSubmissions() {
  const [submissions, setSubmissions] = useState<SubmissionItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [selectedSub, setSelectedSub] = useState<SubmissionItem | null>(null);
  const [scoreInput, setScoreInput] = useState<number | string>('');
  const [feedbackInput, setFeedbackInput] = useState('');
  const [savingGrade, setSavingGrade] = useState(false);
  const [error, setError] = useState('');
  const [successMsg, setSuccessMsg] = useState('');

  const loadSubmissions = () => {
    setLoading(true);
    API.get<SubmissionItem[]>('/admin/assignments/submissions')
      .then((res) => {
        setSubmissions(Array.isArray(res.data) ? res.data : []);
      })
      .catch(() => setError('Failed to load assignment submissions.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadSubmissions();
  }, []);

  const openGradingDrawer = (sub: SubmissionItem) => {
    setSelectedSub(sub);
    setScoreInput(sub.score ?? '');
    setFeedbackInput(sub.feedback ?? '');
    setError('');
    setSuccessMsg('');
  };

  const handleGradeSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedSub) return;

    setSavingGrade(true);
    setError('');

    try {
      await API.post(`/admin/assignments/submissions/${selectedSub.id}/grade`, {
        score: Number(scoreInput),
        feedback: feedbackInput || undefined,
        status: 'graded',
      });

      setSuccessMsg('Grade and feedback saved successfully!');
      setSelectedSub(null);
      loadSubmissions();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to submit grade.');
    } finally {
      setSavingGrade(false);
    }
  };

  const filteredSubmissions = submissions.filter((s) => {
    if (statusFilter === 'all') return true;
    return s.status === statusFilter;
  });

  const pendingCount = submissions.filter((s) => s.status === 'submitted').length;

  return (
    <main className="min-h-screen bg-slate-50 py-10 px-4 sm:px-6 lg:px-8">
      <div className="max-w-7xl mx-auto">
        {/* Top Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
          <div>
            <Link
              to="/admin"
              className="text-xs font-bold text-blue-600 hover:text-blue-800 transition"
            >
              ← Back to Admin Portal
            </Link>
            <h1 className="text-3xl font-extrabold text-slate-900 mt-2">
              Assignment Grading Desk
            </h1>
            <p className="text-sm text-slate-500 mt-1">
              Review student project submissions, assign numerical marks, and post instructor feedback.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <span className="bg-amber-50 text-amber-900 border border-amber-200 px-4 py-2 rounded-xl text-xs font-bold">
              ⏳ {pendingCount} Pending Review
            </span>
          </div>
        </div>

        {/* Filter Pills */}
        <div className="flex items-center gap-2 mb-6">
          {['all', 'submitted', 'graded', 'returned'].map((status) => (
            <button
              key={status}
              type="button"
              onClick={() => setStatusFilter(status)}
              className={`px-4 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition ${
                statusFilter === status
                  ? 'bg-blue-600 text-white shadow'
                  : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
              }`}
            >
              {status}
            </button>
          ))}
        </div>

        {error && <div className="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-700">{error}</div>}
        {successMsg && <div className="mb-6 rounded-2xl bg-green-50 p-4 text-sm text-green-700 font-bold">✓ {successMsg}</div>}

        {/* Submissions Table */}
        <div className="bg-white rounded-3xl ring-1 ring-slate-200 shadow-sm overflow-hidden">
          {loading ? (
            <div className="p-12 text-center text-slate-500">Loading submissions...</div>
          ) : filteredSubmissions.length === 0 ? (
            <div className="p-12 text-center text-slate-400 italic">
              No submissions found for the selected filter.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm text-slate-600">
                <thead className="bg-slate-50/80 text-xs font-extrabold uppercase tracking-wider text-slate-400 border-b border-slate-200">
                  <tr>
                    <th className="px-6 py-4">Student</th>
                    <th className="px-6 py-4">Course & Assignment</th>
                    <th className="px-6 py-4">Status</th>
                    <th className="px-6 py-4">Score</th>
                    <th className="px-6 py-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {filteredSubmissions.map((sub) => (
                    <tr key={sub.id} className="hover:bg-slate-50/50 transition">
                      <td className="px-6 py-4">
                        <p className="font-bold text-slate-900">{sub.user?.name || 'Student'}</p>
                        <p className="text-xs text-slate-400">{sub.user?.email}</p>
                      </td>
                      <td className="px-6 py-4">
                        <p className="font-semibold text-slate-800">{sub.assignment?.title}</p>
                        <p className="text-xs text-slate-400">{sub.course?.title}</p>
                      </td>
                      <td className="px-6 py-4">
                        <span
                          className={`inline-block px-2.5 py-1 rounded-full text-xs font-bold uppercase ${
                            sub.status === 'graded'
                              ? 'bg-green-50 text-green-700'
                              : 'bg-amber-50 text-amber-700'
                          }`}
                        >
                          {sub.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 font-mono font-bold text-slate-900">
                        {sub.score !== null
                          ? `${sub.score} / ${sub.assignment?.max_marks}`
                          : '—'}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button
                          type="button"
                          onClick={() => openGradingDrawer(sub)}
                          className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition shadow"
                        >
                          {sub.status === 'graded' ? 'Update Grade' : 'Grade Submission'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Grading Drawer / Modal */}
      {selectedSub && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
          <form
            onSubmit={handleGradeSubmit}
            className="w-full max-w-2xl bg-white rounded-3xl p-8 shadow-2xl space-y-6 max-h-[90vh] overflow-y-auto"
          >
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <div>
                <span className="text-xs font-bold uppercase tracking-wider text-slate-400">
                  Grading Evaluation
                </span>
                <h3 className="text-xl font-bold text-slate-900 mt-0.5">
                  {selectedSub.assignment?.title}
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setSelectedSub(null)}
                className="text-slate-400 hover:text-slate-700"
              >
                ✕
              </button>
            </div>

            {/* Student Info */}
            <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 text-xs flex justify-between">
              <div>
                <span className="text-slate-400 block font-semibold">Student</span>
                <span className="font-bold text-slate-900">{selectedSub.user?.name} ({selectedSub.user?.email})</span>
              </div>
              <div>
                <span className="text-slate-400 block font-semibold">Max Points</span>
                <span className="font-bold text-slate-900">{selectedSub.assignment?.max_marks} Points</span>
              </div>
            </div>

            {/* Student Submission Text */}
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                Student Written Solution
              </h4>
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-800 whitespace-pre-line leading-relaxed">
                {selectedSub.submission_text || 'No written notes provided.'}
              </div>
            </div>

            {/* Project Link */}
            {selectedSub.file_url && (
              <div>
                <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                  Project Link / Repository
                </h4>
                <a
                  href={selectedSub.file_url}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-2 p-3 rounded-xl bg-purple-50 border border-purple-200 text-xs font-bold text-purple-700 hover:bg-purple-100 transition truncate max-w-full"
                >
                  <span>🔗</span>
                  <span className="truncate">{selectedSub.file_url}</span>
                  <span>↗</span>
                </a>
              </div>
            )}

            {/* Score input */}
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Score (out of {selectedSub.assignment?.max_marks || 100})
              </label>
              <input
                type="number"
                min={0}
                max={selectedSub.assignment?.max_marks || 100}
                step="0.5"
                value={scoreInput}
                onChange={(e) => setScoreInput(e.target.value)}
                placeholder="e.g. 95"
                className="w-full rounded-xl border border-slate-300 p-3 text-sm font-bold text-slate-900 focus:border-blue-600 outline-none"
                required
              />
            </div>

            {/* Feedback textarea */}
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Instructor Feedback & Comments
              </label>
              <textarea
                rows={4}
                value={feedbackInput}
                onChange={(e) => setFeedbackInput(e.target.value)}
                placeholder="Provide constructive feedback and recommendations for the student..."
                className="w-full rounded-xl border border-slate-300 p-3 text-sm text-slate-900 focus:border-blue-600 outline-none"
              />
            </div>

            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
              <button
                type="button"
                onClick={() => setSelectedSub(null)}
                className="px-5 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={savingGrade}
                className="px-6 py-2.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow disabled:opacity-50"
              >
                {savingGrade ? 'Saving Grade...' : 'Save Grade & Feedback ✓'}
              </button>
            </div>
          </form>
        </div>
      )}
    </main>
  );
}
