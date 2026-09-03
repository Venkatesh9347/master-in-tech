interface QuizResultModalProps {
  score: number;
  totalMarks: number;
  percentage: number;
  passingScore: number;
  passed: boolean;
  onRetry: () => void;
  onContinue: () => void;
}

export default function QuizResultModal({
  score,
  totalMarks,
  percentage,
  passingScore,
  passed,
  onRetry,
  onContinue,
}: QuizResultModalProps) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 animate-in fade-in duration-200">
      <div className="w-full max-w-lg bg-white rounded-3xl p-8 shadow-2xl ring-1 ring-slate-200 text-center">
        {/* Result Icon */}
        <div
          className={`w-20 h-20 rounded-full mx-auto mb-5 flex items-center justify-center text-4xl shadow-inner ${
            passed ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
          }`}
        >
          {passed ? '🏆' : '📚'}
        </div>

        <h2 className="text-2xl font-black text-slate-900 mb-2">
          {passed ? 'Quiz Passed! Congratulations!' : 'Keep Going! Review & Try Again'}
        </h2>

        <p className="text-sm text-slate-600 mb-6">
          {passed
            ? 'Great job demonstrating your knowledge of this module.'
            : `You need at least ${passingScore}% to pass this assessment.`}
        </p>

        {/* Score Card */}
        <div className="bg-slate-50 rounded-2xl p-6 mb-8 border border-slate-100 grid grid-cols-2 gap-4">
          <div>
            <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
              Your Score
            </p>
            <p className="text-3xl font-extrabold text-slate-900 mt-1">
              {score} <span className="text-base text-slate-400 font-medium">/ {totalMarks}</span>
            </p>
          </div>
          <div>
            <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
              Percentage
            </p>
            <p
              className={`text-3xl font-extrabold mt-1 ${
                passed ? 'text-green-600' : 'text-amber-600'
              }`}
            >
              {percentage}%
            </p>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex flex-col sm:flex-row gap-3 justify-center">
          {!passed && (
            <button
              type="button"
              onClick={onRetry}
              className="w-full sm:w-auto px-6 py-3 rounded-xl font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition"
            >
              Retake Quiz 🔄
            </button>
          )}

          <button
            type="button"
            onClick={onContinue}
            className={`w-full sm:w-auto px-8 py-3 rounded-xl font-bold text-white shadow-lg transition ${
              passed
                ? 'bg-green-600 hover:bg-green-700 shadow-green-600/20'
                : 'bg-blue-600 hover:bg-blue-700 shadow-blue-600/20'
            }`}
          >
            Continue Learning →
          </button>
        </div>
      </div>
    </div>
  );
}
