import { useState, useEffect, useRef } from 'react';
import { useAuth } from '../context/useAuth';
import API from '../services/api';
import { getCourseAccessStorageKey } from '../utils/courseAccess';

interface CatalogCourseOption {
  id: number;
  title: string;
  category?: string;
  slug?: string;
  code?: string;
}

// Program options are loaded from the live course catalog (/api/courses).
// No hardcoded fallback: an empty/unreachable catalog renders honestly as empty.

function parseCourseCatalog(payload: unknown): CatalogCourseOption[] {
  const rows = Array.isArray(payload)
    ? payload
    : (payload && typeof payload === 'object' && 'data' in payload
        ? (payload as { data: unknown }).data
        : []);
  if (!Array.isArray(rows)) return [];

  const options: CatalogCourseOption[] = [];
  for (const row of rows) {
    if (!row || typeof row !== 'object') continue;
    const course = row as { id?: number; title?: string; category?: string; slug?: string; code?: string };
    if (!course.title) continue;
    options.push({
      id: Number(course.id) || 0,
      title: course.title,
      category: course.category,
      slug: course.slug,
      code: course.code,
    });
  }
  return options;
}

interface PublicAccessGateModalProps {
  isOpen: boolean;
  onClose: () => void;
  courseId?: number;
  courseTitle?: string;
  onSuccess?: () => void;
}

export default function PublicAccessGateModal({
  isOpen,
  onClose,
  courseId,
  courseTitle: initialCourseTitle,
  onSuccess,
}: PublicAccessGateModalProps) {
  const { user } = useAuth();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [courseTitle, setCourseTitle] = useState(initialCourseTitle || '');
  const [selectedCourseId, setSelectedCourseId] = useState<number | undefined>(courseId);
  const [programOptions, setProgramOptions] = useState<CatalogCourseOption[]>([]);

  const [errors, setErrors] = useState<{ name?: string; email?: string; phone?: string }>({});
  const [submitting, setSubmitting] = useState(false);
  const [serverError, setServerError] = useState('');
  const [isSuccess, setIsSuccess] = useState(false);

  const nameInputRef = useRef<HTMLInputElement>(null);

  // Sync course title prop
  useEffect(() => {
    if (initialCourseTitle) {
      setCourseTitle(initialCourseTitle);
    }
    if (courseId) {
      setSelectedCourseId(courseId);
    }
  }, [initialCourseTitle, courseId]);

  useEffect(() => {
    if (!isOpen) return;

    API.get('/courses')
      .then((res) => {
        // Preserve server (C-panel priority) ordering — never re-sort client-side.
        const catalog = parseCourseCatalog(res.data);
        setProgramOptions(catalog);

        const preferredTitle = (initialCourseTitle || '').trim().toLowerCase();
        if (preferredTitle) {
          const match = catalog.find((course) => course.title.trim().toLowerCase() === preferredTitle);
          if (match) {
            setCourseTitle(match.title);
            if (match.id > 0) setSelectedCourseId(match.id);
          }
        }
      })
      .catch(() => {
        setProgramOptions([]);
      });
  }, [isOpen, initialCourseTitle]);

  // Lock body scroll when modal is active
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
      const timer = setTimeout(() => {
        nameInputRef.current?.focus();
      }, 100);
      return () => {
        document.body.style.overflow = 'unset';
        clearTimeout(timer);
      };
    } else {
      document.body.style.overflow = 'unset';
    }
  }, [isOpen]);

  // Trap escape key while gate is open
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (isOpen && e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
      }
    };
    window.addEventListener('keydown', handleKeyDown, true);
    return () => window.removeEventListener('keydown', handleKeyDown, true);
  }, [isOpen]);

  // If user is already authenticated (Student/Tutor/Admin), they do not need the lead gate
  if (!isOpen || user) return null;

  const validateForm = () => {
    const newErrors: { name?: string; email?: string; phone?: string } = {};

    if (!name.trim()) {
      newErrors.name = 'Full name is required.';
    } else if (name.trim().length < 2) {
      newErrors.name = 'Name must be at least 2 characters.';
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email.trim()) {
      newErrors.email = 'Email address is required.';
    } else if (!emailRegex.test(email.trim())) {
      newErrors.email = 'Please enter a valid email address.';
    }

    // Validate 10-digit Indian phone or standard international phone
    const cleanPhone = phone.replace(/[\s\-()]/g, '');
    const phoneRegex = /^(\+?\d{1,3})?[6-9]\d{9}$|^\d{10,12}$/;
    if (!phone.trim()) {
      newErrors.phone = 'Mobile number is required.';
    } else if (!phoneRegex.test(cleanPhone)) {
      newErrors.phone = 'Please enter a valid 10-digit mobile number.';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setServerError('');

    if (!validateForm()) {
      return;
    }

    setSubmitting(true);

    try {
      // Submit lead to existing backend /api/enquiries endpoint
      await API.post('/enquiries', {
        name: name.trim(),
        email: email.trim(),
        phone: phone.trim(),
        course_id: selectedCourseId || courseId || undefined,
        course_title: courseTitle.trim() || initialCourseTitle || 'General Technology Program',
        message: 'Course admission enquiry',
      });

      const storageKey = getCourseAccessStorageKey(courseId);
      localStorage.setItem(storageKey, 'true');
      setIsSuccess(true);

      if (onSuccess) {
        onSuccess();
      }

      // Close modal after confirmation
      setTimeout(() => {
        onClose();
        setIsSuccess(false);
      }, 2500);
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      if (response.response?.data?.message && response.response.data.message.includes('already exists')) {
        const storageKey = getCourseAccessStorageKey(courseId);
        localStorage.setItem(storageKey, 'true');
        setIsSuccess(true);
        if (onSuccess) onSuccess();
        setTimeout(() => {
          onClose();
          setIsSuccess(false);
        }, 2500);
      } else {
        setServerError(response.response?.data?.message || 'Unable to submit details. Please check your connection.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6 bg-slate-950/80 backdrop-blur-sm animate-in fade-in duration-200"
      role="dialog"
      aria-modal="true"
      aria-labelledby="course-access-title"
    >
      <div className="w-full max-w-3xl bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col md:flex-row relative">
        {/* Close Button */}
        <button
          type="button"
          onClick={onClose}
          className="absolute top-3 right-3 z-10 w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-900 flex items-center justify-center text-sm font-bold transition"
          aria-label="Close modal"
        >
          ✕
        </button>

        {/* Left Side: Branding & Educational Value */}
        <div className="md:w-5/12 bg-slate-900 text-white p-6 sm:p-8 flex flex-col justify-between border-b md:border-b-0 md:border-r border-slate-800">
          <div>
            <div className="flex items-center gap-2.5 mb-6">
              <div className="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-black text-base shadow-sm">
                M
              </div>
              <span className="text-lg font-black tracking-tight text-white">
                Master<span className="text-blue-400">In</span>Tech
              </span>
            </div>

            <div className="space-y-3">
              <span className="text-[10px] font-extrabold uppercase tracking-wider text-blue-400">
                Admissions & Counselling
              </span>
              <h2 className="text-xl sm:text-2xl font-black text-white leading-tight">
                Accelerate Your Tech Career.
              </h2>
              <p className="text-xs text-slate-300 leading-relaxed font-normal">
                Submit an enquiry to connect with our academic counselling team, review prerequisites, and get your student account activated.
              </p>
            </div>
          </div>

          <div className="mt-6 pt-6 border-t border-slate-800 space-y-2">
            <div className="flex items-center gap-2 text-xs text-slate-300">
              <span className="text-blue-400 font-bold">✓</span>
              <span>Dedicated Faculty Counselling</span>
            </div>
            <div className="flex items-center gap-2 text-xs text-slate-300">
              <span className="text-blue-400 font-bold">✓</span>
              <span>100% Practical Capstone Projects</span>
            </div>
            <div className="flex items-center gap-2 text-xs text-slate-300">
              <span className="text-blue-400 font-bold">✓</span>
              <span>Industry-Recognized Certification</span>
            </div>
          </div>
        </div>

        {/* Right Side: Lead Capture Form */}
        <div className="md:w-7/12 p-6 sm:p-8 bg-white flex flex-col justify-center">
          {isSuccess ? (
            <div className="text-center py-8 space-y-3">
              <div className="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 text-2xl flex items-center justify-center mx-auto border border-emerald-200">
                ✓
              </div>
              <h3 className="text-base font-black text-slate-900">Enquiry Received!</h3>
              <p className="text-xs text-slate-600 max-w-xs mx-auto">
                Thank you! Our academic admissions team will contact you shortly regarding syllabus details and student account activation.
              </p>
              <button
                type="button"
                onClick={onClose}
                className="mt-4 px-4 py-2 rounded-xl text-xs font-bold bg-slate-900 text-white hover:bg-slate-800 transition"
              >
                Done
              </button>
            </div>
          ) : (
            <div>
              <div className="mb-5">
                <h3 id="course-access-title" className="text-lg font-black text-slate-900 tracking-tight">
                  {initialCourseTitle ? `Enquire About: ${initialCourseTitle}` : 'Course Enquiry & Counselling'}
                </h3>
                <p className="text-xs text-slate-500 mt-1">
                  Leave your details and our team will get in touch with you shortly.
                </p>
              </div>

              {serverError && (
                <div className="mb-4 p-2.5 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs font-medium">
                  {serverError}
                </div>
              )}

              <form onSubmit={handleSubmit} className="space-y-3.5" noValidate>
                {/* Full Name */}
                <div>
                  <label htmlFor="access-gate-name" className="block text-xs font-bold text-slate-700 mb-1">
                    Full Name <span className="text-red-500">*</span>
                  </label>
                  <input
                    ref={nameInputRef}
                    id="access-gate-name"
                    type="text"
                    value={name}
                    onChange={(e) => {
                      setName(e.target.value);
                      if (errors.name) setErrors((prev) => ({ ...prev, name: undefined }));
                    }}
                    placeholder="e.g. Alex Sharma"
                    className={`w-full px-3 py-2 text-xs rounded-lg border bg-slate-50 focus:bg-white focus:outline-none transition ${
                      errors.name
                        ? 'border-red-400 focus:border-red-500 focus:ring-1 focus:ring-red-500'
                        : 'border-slate-300 focus:border-blue-600 focus:ring-1 focus:ring-blue-600'
                    }`}
                  />
                  {errors.name && <p className="text-[11px] text-red-600 mt-0.5">{errors.name}</p>}
                </div>

                {/* Email Address */}
                <div>
                  <label htmlFor="access-gate-email" className="block text-xs font-bold text-slate-700 mb-1">
                    Email Address <span className="text-red-500">*</span>
                  </label>
                  <input
                    id="access-gate-email"
                    type="email"
                    value={email}
                    onChange={(e) => {
                      setEmail(e.target.value);
                      if (errors.email) setErrors((prev) => ({ ...prev, email: undefined }));
                    }}
                    placeholder="name@example.com"
                    className={`w-full px-3 py-2 text-xs rounded-lg border bg-slate-50 focus:bg-white focus:outline-none transition ${
                      errors.email
                        ? 'border-red-400 focus:border-red-500 focus:ring-1 focus:ring-red-500'
                        : 'border-slate-300 focus:border-blue-600 focus:ring-1 focus:ring-blue-600'
                    }`}
                  />
                  {errors.email && <p className="text-[11px] text-red-600 mt-0.5">{errors.email}</p>}
                </div>

                {/* Mobile Number */}
                <div>
                  <label htmlFor="access-gate-phone" className="block text-xs font-bold text-slate-700 mb-1">
                    Mobile Number <span className="text-red-500">*</span>
                  </label>
                  <input
                    id="access-gate-phone"
                    type="tel"
                    value={phone}
                    onChange={(e) => {
                      setPhone(e.target.value);
                      if (errors.phone) setErrors((prev) => ({ ...prev, phone: undefined }));
                    }}
                    placeholder="e.g. 9876543210"
                    className={`w-full px-3 py-2 text-xs rounded-lg border bg-slate-50 focus:bg-white focus:outline-none transition ${
                      errors.phone
                        ? 'border-red-400 focus:border-red-500 focus:ring-1 focus:ring-red-500'
                        : 'border-slate-300 focus:border-blue-600 focus:ring-1 focus:ring-blue-600'
                    }`}
                  />
                  {errors.phone && <p className="text-[11px] text-red-600 mt-0.5">{errors.phone}</p>}
                </div>

                {/* Optional Interested Program */}
                <div>
                  <label htmlFor="access-gate-program" className="block text-xs font-bold text-slate-700 mb-1">
                    Interested Program
                  </label>
                  <select
                    id="access-gate-program"
                    value={selectedCourseId && selectedCourseId > 0 ? String(selectedCourseId) : courseTitle}
                    onChange={(e) => {
                      const value = e.target.value;
                      const byId = programOptions.find((course) => course.id > 0 && String(course.id) === value);
                      if (byId) {
                        setSelectedCourseId(byId.id);
                        setCourseTitle(byId.title);
                        return;
                      }
                      const byTitle = programOptions.find((course) => course.title === value);
                      setSelectedCourseId(byTitle && byTitle.id > 0 ? byTitle.id : undefined);
                      setCourseTitle(byTitle?.title || value);
                    }}
                    className="w-full px-3 py-2 text-xs rounded-lg border border-slate-300 bg-slate-50 focus:bg-white focus:border-blue-600 focus:outline-none transition text-slate-800"
                  >
                    <option value="">Select a technology track...</option>
                    {programOptions.map((program) => (
                      <option
                        key={program.id > 0 ? `course-${program.id}` : `title-${program.title}`}
                        value={program.id > 0 ? String(program.id) : program.title}
                      >
                        {program.title}
                      </option>
                    ))}
                  </select>
                </div>

                {/* Submit Button */}
                <div className="pt-2">
                  <button
                    type="submit"
                    disabled={submitting}
                    className="w-full py-2.5 px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-xs flex items-center justify-center gap-2 disabled:opacity-50"
                  >
                    {submitting ? (
                      <>
                        <span className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                        <span>Submitting Enquiry...</span>
                      </>
                    ) : (
                      <span>Submit Course Enquiry →</span>
                    )}
                  </button>
                </div>
              </form>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
