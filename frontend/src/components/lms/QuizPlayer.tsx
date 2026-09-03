import { useEffect, useState, useCallback } from 'react';
import API from '../../services/api';
import type { Quiz, QuizAttempt } from '../../types/lms';
import QuizResultModal from './QuizResultModal';

interface QuizPlayerProps {
  quiz: Quiz;
  courseId: number;
  onComplete: () => void;
}

interface QuizSubmissionResult {
  score: number;
  total_marks: number;
  percentage: number;
  passing_score: number;
  passed: boolean;
}

export default function QuizPlayer({ quiz, courseId: _courseId, onComplete }: QuizPlayerProps) {
  const [attempt, setAttempt] = useState<QuizAttempt | null>(null);
  const [selectedAnswers, setSelectedAnswers] = useState<Record<number, number>>({});
  const [timeLeft, setTimeLeft] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [starting, setStarting] = useState(false);
  const [result, setResult] = useState<QuizSubmissionResult | null>(null);
  const [error, setError] = useState('');

  const handleSubmit = useCallback(async () => {
    if (!attempt || submitting) return;

    setSubmitting(true);
    setError('');

    const formattedAnswers = Object.entries(selectedAnswers).map(([qId, oId]) => ({
      question_id: Number(qId),
      option_id: Number(oId),
    }));

    // For any unselected questions in the quiz, provide null option
    const questions = quiz.questions || [];
    questions.forEach((q) => {
      if (!selectedAnswers[q.id]) {
        formattedAnswers.push({
          question_id: q.id,
          option_id: undefined as unknown as number,
        });
      }
    });

    try {
      const res = await API.post<QuizSubmissionResult>(
        `/quiz-attempts/${attempt.id}/submit`,
        { answers: formattedAnswers }
      );
      setResult(res.data);
      if (res.data.passed) {
        onComplete();
      }
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to submit quiz.');
    } finally {
      setSubmitting(false);
    }
  }, [attempt, submitting, selectedAnswers, quiz.questions, onComplete]);

  const handleStartQuiz = async () => {
    setStarting(true);
    setError('');
    try {
      const res = await API.post<{ attempt: QuizAttempt }>(`/quizzes/${quiz.id}/start`);
      const newAttempt = res.data.attempt || res.data;
      setAttempt(newAttempt);
      setSelectedAnswers({});
      setResult(null);

      if (quiz.time_limit && quiz.time_limit > 0) {
        setTimeLeft(quiz.time_limit * 60);
      }
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to start quiz attempt.');
    } finally {
      setStarting(false);
    }
  };

  const handleSelectOption = (questionId: number, optionId: number) => {
    setSelectedAnswers((prev) => ({
      ...prev,
      [questionId]: optionId,
    }));
  };

  // Countdown timer
  useEffect(() => {
    if (timeLeft === null || timeLeft <= 0 || !attempt || result) return;

    const timer = setInterval(() => {
      setTimeLeft((prev) => {
        if (prev === null || prev <= 1) {
          clearInterval(timer);
          void handleSubmit();
          return 0;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(timer);
  }, [timeLeft, attempt, result, handleSubmit]);

  const formatTimer = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
  };

  const questions = quiz.questions || [];

  return (
    <div className="flex flex-col space-y-6">
      {/* Intro Screen */}
      {!attempt && (
        <div className="bg-white p-8 rounded-2xl ring-1 ring-slate-200 shadow-sm text-center">
          <div className="w-16 h-16 rounded-2xl bg-amber-100 text-amber-700 mx-auto mb-4 flex items-center justify-center text-3xl">
            ❓
          </div>

          <h1 className="text-3xl font-extrabold text-slate-900 mb-3">
            {quiz.title}
          </h1>

          {quiz.description && (
            <p className="text-slate-600 max-w-xl mx-auto mb-6 text-base leading-relaxed">
              {quiz.description}
            </p>
          )}

          {/* Rules / Overview */}
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 max-w-xl mx-auto mb-8 text-left">
            <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">
              <p className="text-xs font-semibold text-slate-500 uppercase">Questions</p>
              <p className="text-lg font-bold text-slate-900 mt-1">{questions.length}</p>
            </div>
            <div className="p-4 rounded-xl bg-slate-50 border border-slate-100">
              <p className="text-xs font-semibold text-slate-500 uppercase">Passing Score</p>
              <p className="text-lg font-bold text-slate-900 mt-1">{quiz.passing_score}%</p>
            </div>
            <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 col-span-2 sm:col-span-1">
              <p className="text-xs font-semibold text-slate-500 uppercase">Time Limit</p>
              <p className="text-lg font-bold text-slate-900 mt-1">
                {quiz.time_limit ? `${quiz.time_limit} Mins` : 'Unlimited'}
              </p>
            </div>
          </div>

          {error && (
            <p className="mb-6 rounded-lg bg-red-50 p-3 text-sm text-red-700 max-w-xl mx-auto">
              {error}
            </p>
          )}

          <button
            type="button"
            onClick={handleStartQuiz}
            disabled={starting}
            className="inline-flex items-center gap-2 px-8 py-3.5 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 transition shadow-lg shadow-blue-600/20 disabled:opacity-50"
          >
            {starting ? 'Preparing Quiz...' : 'Start Assessment 🚀'}
          </button>
        </div>
      )}

      {/* Active Quiz Player */}
      {attempt && (
        <div className="bg-white p-8 rounded-2xl ring-1 ring-slate-200 shadow-sm">
          {/* Active Header */}
          <div className="flex flex-wrap items-center justify-between gap-4 mb-8 pb-6 border-b border-slate-200">
            <div>
              <span className="text-xs font-bold uppercase tracking-wider text-blue-600">
                Assessment In Progress
              </span>
              <h2 className="text-2xl font-bold text-slate-900 mt-1">{quiz.title}</h2>
            </div>

            {timeLeft !== null && (
              <div className="flex items-center gap-2 bg-amber-50 border border-amber-200 px-4 py-2 rounded-xl text-amber-900 font-mono font-bold">
                <span>⏱</span>
                <span>{formatTimer(timeLeft)}</span>
              </div>
            )}
          </div>

          {/* Question List */}
          <div className="space-y-8">
            {questions.map((q, qIdx) => (
              <div
                key={q.id}
                className="p-6 rounded-2xl bg-slate-50/70 border border-slate-200/80"
              >
                <div className="flex items-start justify-between gap-4 mb-4">
                  <h3 className="text-base font-bold text-slate-900">
                    <span className="text-blue-600 mr-2">Q{qIdx + 1}.</span>
                    {q.question}
                  </h3>
                  <span className="text-xs font-semibold text-slate-500 bg-white px-2.5 py-1 rounded-full border border-slate-200 flex-shrink-0">
                    {q.marks} {q.marks === 1 ? 'Mark' : 'Marks'}
                  </span>
                </div>

                {/* Option Choices */}
                <div className="space-y-2.5">
                  {(q.options || []).map((opt) => {
                    const isSelected = selectedAnswers[q.id] === opt.id;
                    return (
                      <label
                        key={opt.id}
                        className={`flex items-center gap-3 p-3.5 rounded-xl border cursor-pointer transition ${
                          isSelected
                            ? 'bg-blue-50/80 border-blue-500 text-blue-900 font-semibold shadow-sm'
                            : 'bg-white border-slate-200 hover:bg-slate-50 text-slate-700'
                        }`}
                      >
                        <input
                          type="radio"
                          name={`question_${q.id}`}
                          checked={isSelected}
                          onChange={() => handleSelectOption(q.id, opt.id)}
                          className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-slate-300"
                        />
                        <span className="text-sm">{opt.option_text}</span>
                      </label>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>

          {error && (
            <p className="mt-6 rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>
          )}

          {/* Submit Footer */}
          <div className="mt-8 pt-6 border-t border-slate-200 flex items-center justify-between">
            <p className="text-xs text-slate-500 font-medium">
              Answered {Object.keys(selectedAnswers).length} of {questions.length} questions
            </p>

            <button
              type="button"
              onClick={handleSubmit}
              disabled={submitting}
              className="px-8 py-3 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 transition shadow-md shadow-blue-600/20 disabled:opacity-50"
            >
              {submitting ? 'Evaluating Answers...' : 'Submit Assessment ✓'}
            </button>
          </div>
        </div>
      )}

      {/* Result Modal */}
      {result && (
        <QuizResultModal
          score={result.score}
          totalMarks={result.total_marks}
          percentage={result.percentage}
          passingScore={result.passing_score}
          passed={result.passed}
          onRetry={handleStartQuiz}
          onContinue={() => {
            setResult(null);
            setAttempt(null);
            onComplete();
          }}
        />
      )}
    </div>
  );
}
