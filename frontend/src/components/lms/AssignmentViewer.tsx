import { useEffect, useState } from 'react';
import API from '../../services/api';
import type { Assignment, AssignmentSubmission } from '../../types/lms';

interface AssignmentViewerProps {
  assignment: Assignment;
  onComplete: () => void;
}

interface AssignmentDataResponse {
  assignment: Assignment;
  submission: AssignmentSubmission | null;
}

export default function AssignmentViewer({ assignment, onComplete }: AssignmentViewerProps) {
  const [submission, setSubmission] = useState<AssignmentSubmission | null>(null);
  const [submissionText, setSubmissionText] = useState('');
  const [fileUrl, setFileUrl] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [successMsg, setSuccessMsg] = useState('');

  useEffect(() => {
    setLoading(true);
    setError('');
    API.get<AssignmentDataResponse>(`/assignments/${assignment.id}`)
      .then((res) => {
        if (res.data.submission) {
          setSubmission(res.data.submission);
          setSubmissionText(res.data.submission.submission_text || '');
          setFileUrl(res.data.submission.file_url || '');
        }
      })
      .catch((err: unknown) => {
        const response = err as { response?: { data?: { message?: string } } };
        setError(response.response?.data?.message || 'Failed to load assignment details.');
      })
      .finally(() => setLoading(false));
  }, [assignment.id]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!submissionText.trim() && !fileUrl.trim()) {
      setError('Please provide a written response or project URL.');
      return;
    }

    setSubmitting(true);
    setError('');
    setSuccessMsg('');

    try {
      const res = await API.post<{ submission: AssignmentSubmission }>(
        `/assignments/${assignment.id}/submit`,
        {
          submission_text: submissionText,
          file_url: fileUrl || undefined,
        }
      );
      setSubmission(res.data.submission);
      setSuccessMsg('Assignment submitted successfully! Your instructor will review it.');
      onComplete();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to submit assignment.');
    } finally {
      setSubmitting(false);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'graded':
        return (
          <span className="rounded-full bg-green-100 text-green-800 px-3 py-1 text-xs font-bold">
            ✓ Graded
          </span>
        );
      case 'returned':
        return (
          <span className="rounded-full bg-blue-100 text-blue-800 px-3 py-1 text-xs font-bold">
            ↩ Returned for revision
          </span>
        );
      case 'submitted':
      default:
        return (
          <span className="rounded-full bg-amber-100 text-amber-800 px-3 py-1 text-xs font-bold">
            ⏳ Submitted — Pending Review
          </span>
        );
    }
  };

  if (loading) {
    return (
      <div className="bg-white p-8 rounded-2xl ring-1 ring-slate-200 shadow-sm text-center py-16">
        <p className="text-slate-500">Loading assignment details...</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col space-y-6">
      {/* Assignment Overview Card */}
      <div className="bg-white p-8 rounded-2xl ring-1 ring-slate-200 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4 pb-4 border-b border-slate-100">
          <div className="flex items-center gap-2">
            <span className="rounded-full bg-purple-50 px-3 py-1 text-xs font-bold text-purple-700">
              📝 Practical Assignment
            </span>
            <span className="text-xs font-bold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-full">
              Max Score: {assignment.max_marks} Points
            </span>
          </div>

          {submission && getStatusBadge(submission.status)}
        </div>

        <h1 className="text-3xl font-extrabold text-slate-900 mb-4">
          {assignment.title}
        </h1>

        {assignment.instructions && (
          <div className="bg-slate-50 p-6 rounded-xl border border-slate-100 text-slate-700 text-sm leading-relaxed mb-6 whitespace-pre-line">
            <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">
              Assignment Instructions
            </h3>
            {assignment.instructions}
          </div>
        )}

        {assignment.file_url && (
          <div className="mb-6">
            <a
              href={assignment.file_url}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-2 rounded-xl bg-purple-50 border border-purple-200 px-4 py-2.5 text-xs font-bold text-purple-700 hover:bg-purple-100 transition"
            >
              <span>📁 Download Starter Code & Files</span>
              <span>↓</span>
            </a>
          </div>
        )}

        {/* Existing Grade & Feedback Notice (if graded) */}
        {submission && submission.status === 'graded' && (
          <div className="mb-8 rounded-2xl bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 p-6">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-base font-bold text-green-950 flex items-center gap-2">
                <span>🏆</span> Instructor Evaluation
              </h3>
              <span className="text-2xl font-black text-green-800">
                {submission.score} <span className="text-sm font-normal text-green-600">/ {assignment.max_marks}</span>
              </span>
            </div>

            {submission.feedback && (
              <div className="bg-white/80 p-4 rounded-xl border border-green-100 text-sm text-green-900">
                <p className="font-bold text-xs uppercase tracking-wider text-green-700 mb-1">Feedback Notes</p>
                <p className="whitespace-pre-line leading-relaxed">{submission.feedback}</p>
              </div>
            )}
          </div>
        )}

        {/* Student Submission Form */}
        <form onSubmit={handleSubmit} className="mt-6 pt-6 border-t border-slate-200 space-y-5">
          <div>
            <label
              htmlFor="submission_text"
              className="block text-sm font-bold text-slate-800 mb-1.5"
            >
              Your Solution / Summary Notes
            </label>
            <textarea
              id="submission_text"
              rows={5}
              value={submissionText}
              onChange={(e) => setSubmissionText(e.target.value)}
              placeholder="Describe your implementation, architecture decisions, and steps taken..."
              className="w-full rounded-xl border border-slate-300 p-4 text-sm text-slate-900 placeholder:text-slate-400 focus:border-purple-600 focus:ring-1 focus:ring-purple-600 outline-none"
            />
          </div>

          <div>
            <label
              htmlFor="file_url"
              className="block text-sm font-bold text-slate-800 mb-1.5"
            >
              Project Repository / Hosted Demo URL
            </label>
            <input
              type="url"
              id="file_url"
              value={fileUrl}
              onChange={(e) => setFileUrl(e.target.value)}
              placeholder="https://github.com/username/project-repo"
              className="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-purple-600 focus:ring-1 focus:ring-purple-600 outline-none"
            />
          </div>

          {error && (
            <p className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>
          )}

          {successMsg && (
            <p className="rounded-lg bg-green-50 p-3 text-sm text-green-700 font-semibold">
              ✓ {successMsg}
            </p>
          )}

          <div className="flex items-center justify-between pt-2">
            <span className="text-xs text-slate-500">
              {submission ? `Last submitted: ${new Date(submission.updated_at).toLocaleDateString()}` : 'Not submitted yet'}
            </span>

            <button
              type="submit"
              disabled={submitting}
              className="px-8 py-3 rounded-xl font-bold text-white bg-purple-600 hover:bg-purple-700 transition shadow-md shadow-purple-600/20 disabled:opacity-50"
            >
              {submitting
                ? 'Submitting...'
                : submission
                ? 'Update Submission ⟳'
                : 'Submit Assignment 🚀'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
