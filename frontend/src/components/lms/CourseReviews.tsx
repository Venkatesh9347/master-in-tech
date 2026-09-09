import { useEffect, useState } from 'react';
import API from '../../services/api';

interface ReviewItem {
  id: number;
  rating: number;
  review_text: string | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
  };
}

interface CourseReviewsProps {
  courseId: number;
  isEnrolled?: boolean;
}

export default function CourseReviews({ courseId, isEnrolled = false }: CourseReviewsProps) {
  const [reviews, setReviews] = useState<ReviewItem[]>([]);
  const [avgRating, setAvgRating] = useState(0);
  const [reviewCount, setReviewCount] = useState(0);
  const [selectedRating, setSelectedRating] = useState(5);
  const [reviewText, setReviewText] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [successMsg, setSuccessMsg] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    API.get<{ average_rating: number; review_count: number; reviews: ReviewItem[] }>(
      `/courses/${courseId}/reviews`
    )
      .then((res) => {
        setReviews(res.data.reviews || []);
        setAvgRating(res.data.average_rating ?? 0);
        setReviewCount(res.data.review_count || 0);
      })
      .catch(() => {});
  }, [courseId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    setSuccessMsg('');

    try {
      const res = await API.post<{ message: string; review: ReviewItem }>(
        `/courses/${courseId}/reviews`,
        {
          rating: selectedRating,
          review_text: reviewText || undefined,
        }
      );

      setSuccessMsg('Thank you! Your review has been posted.');
      setReviews((prev) => [res.data.review, ...prev.filter((r) => r.id !== res.data.review.id)]);
      setReviewCount((prev) => prev + 1);
      setReviewText('');
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to submit review.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="bg-white p-8 rounded-3xl ring-1 ring-slate-200 shadow-sm mt-12">
      {/* Header & Rating Summary */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-6 pb-6 border-b border-slate-100 mb-8">
        <div>
          <h2 className="text-2xl font-bold text-slate-900">Student Reviews & Ratings</h2>
          <p className="text-sm text-slate-500 mt-1">Real feedback from enrolled students</p>
        </div>

        <div className="flex items-center gap-4 bg-amber-50/80 border border-amber-200 px-5 py-3 rounded-2xl">
          <span className="text-3xl font-black text-amber-900">
            {reviewCount > 0 ? avgRating : '—'}
          </span>
          <div>
            <div className="flex text-amber-500 text-base">
              {reviewCount > 0 && '★'.repeat(Math.round(avgRating))}
              {reviewCount > 0 && '☆'.repeat(5 - Math.round(avgRating))}
            </div>
            <p className="text-xs text-amber-800 font-semibold mt-0.5">
              {reviewCount} {reviewCount === 1 ? 'Rating' : 'Ratings'}
            </p>
          </div>
        </div>
      </div>

      {/* Review Submission Form for Enrolled Students */}
      {isEnrolled && (
        <form onSubmit={handleSubmit} className="mb-10 p-6 rounded-2xl bg-slate-50 border border-slate-200/80">
          <h3 className="text-base font-bold text-slate-900 mb-2">Leave your review</h3>
          <p className="text-xs text-slate-600 mb-4">Share your experience with this course to help fellow students.</p>

          {/* Star selector */}
          <div className="flex items-center gap-2 mb-4">
            <span className="text-xs font-bold text-slate-700 mr-2">Your Rating:</span>
            {[1, 2, 3, 4, 5].map((star) => (
              <button
                key={star}
                type="button"
                onClick={() => setSelectedRating(star)}
                className={`text-2xl transition hover:scale-110 ${
                  star <= selectedRating ? 'text-amber-500' : 'text-slate-300'
                }`}
              >
                ★
              </button>
            ))}
          </div>

          <textarea
            rows={3}
            value={reviewText}
            onChange={(e) => setReviewText(e.target.value)}
            placeholder="Write your review comments here (optional)..."
            className="w-full rounded-xl border border-slate-300 p-3.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 outline-none mb-3 bg-white"
          />

          {error && <p className="text-xs text-red-600 mb-3">{error}</p>}
          {successMsg && <p className="text-xs text-green-600 font-bold mb-3">✓ {successMsg}</p>}

          <button
            type="submit"
            disabled={submitting}
            className="px-6 py-2.5 rounded-xl font-bold text-xs text-white bg-blue-600 hover:bg-blue-700 transition shadow disabled:opacity-50"
          >
            {submitting ? 'Submitting...' : 'Post Review'}
          </button>
        </form>
      )}

      {/* Reviews List */}
      {reviews.length === 0 ? (
        <p className="text-sm text-slate-500 italic py-4">No written reviews yet. Be the first to share your thoughts!</p>
      ) : (
        <div className="divide-y divide-slate-100 space-y-4">
          {reviews.map((r) => (
            <div key={r.id} className="pt-4 first:pt-0">
              <div className="flex items-center justify-between mb-1.5">
                <p className="text-sm font-bold text-slate-900">{r.user?.name || 'Student'}</p>
                <div className="flex text-amber-500 text-sm">
                  {'★'.repeat(r.rating)}
                  {'☆'.repeat(5 - r.rating)}
                </div>
              </div>
              {r.review_text && <p className="text-sm text-slate-600 leading-relaxed">{r.review_text}</p>}
              <span className="text-[11px] text-slate-400 block mt-1">
                {new Date(r.created_at).toLocaleDateString()}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
