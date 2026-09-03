import { useEffect, useState, useCallback } from 'react';
import { Link, useParams } from 'react-router-dom';
import API from '../services/api';
import type { Section, Lesson, LessonType } from '../types/lms';
import type { Course } from '../types/course';

export default function AdminCurriculum() {
  const { courseId } = useParams<{ courseId: string }>();
  const [course, setCourse] = useState<Course | null>(null);
  const [sections, setSections] = useState<Section[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [successMsg, setSuccessMsg] = useState('');

  // Modal States
  const [showSectionModal, setShowSectionModal] = useState(false);
  const [editingSection, setEditingSection] = useState<Section | null>(null);
  const [sectionTitle, setSectionTitle] = useState('');
  const [sectionSort, setSectionSort] = useState(0);

  const [showLessonModal, setShowLessonModal] = useState(false);
  const [targetSectionId, setTargetSectionId] = useState<number | null>(null);
  const [editingLesson, setEditingLesson] = useState<Lesson | null>(null);
  const [lessonTitle, setLessonTitle] = useState('');
  const [lessonDuration, setLessonDuration] = useState('10 min');
  const [lessonType, setLessonType] = useState<LessonType>('video');
  const [lessonSort, setLessonSort] = useState(0);
  const [lessonIsPublished, setLessonIsPublished] = useState(true);
  const [videoUrl, setVideoUrl] = useState('');
  const [lessonContent, setLessonContent] = useState('');
  const [documentUrl, setDocumentUrl] = useState('');
  const [documentTitle, setDocumentTitle] = useState('');
  const [saving, setSaving] = useState(false);

  const loadCurriculum = useCallback(() => {
    if (!courseId) return;
    setLoading(true);

    Promise.all([
      API.get<Course>(`/courses/${courseId}`),
      API.get<Section[]>(`/courses/${courseId}/sections`),
    ])
      .then(([courseRes, secRes]) => {
        setCourse(courseRes.data);
        setSections(Array.isArray(secRes.data) ? secRes.data : []);
      })
      .catch(() => setError('Failed to load course curriculum.'))
      .finally(() => setLoading(false));
  }, [courseId]);

  useEffect(() => {
    loadCurriculum();
  }, [loadCurriculum]);

  // Section Handlers
  const handleSaveSection = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!courseId || !sectionTitle.trim()) return;

    setSaving(true);
    setError('');
    try {
      if (editingSection) {
        await API.put(`/courses/${courseId}/sections/${editingSection.id}`, {
          title: sectionTitle,
          sort_order: sectionSort,
        });
        setSuccessMsg('Section updated successfully.');
      } else {
        await API.post(`/courses/${courseId}/sections`, {
          title: sectionTitle,
          sort_order: sectionSort,
        });
        setSuccessMsg('New section created successfully.');
      }
      setShowSectionModal(false);
      setEditingSection(null);
      setSectionTitle('');
      loadCurriculum();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to save section.');
    } finally {
      setSaving(false);
    }
  };

  const handleTogglePublishSection = async (secId: number) => {
    if (!courseId) return;
    try {
      await API.post(`/courses/${courseId}/sections/${secId}/toggle-publish`);
      setSuccessMsg('Module publishing status updated.');
      loadCurriculum();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to toggle module status.');
    }
  };

  const handleDeleteSection = async (secId: number) => {
    if (!courseId || !window.confirm('Delete this section and all its lessons?')) return;
    try {
      await API.delete(`/courses/${courseId}/sections/${secId}`);
      setSuccessMsg('Section deleted.');
      loadCurriculum();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to delete section.');
    }
  };

  const handleMoveSection = async (index: number, direction: 'up' | 'down') => {
    if (!courseId) return;
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= sections.length) return;

    const newSections = [...sections];
    const temp = newSections[index];
    newSections[index] = newSections[targetIndex];
    newSections[targetIndex] = temp;

    const payload = {
      sections: newSections.map((sec, idx) => ({
        id: sec.id,
        sort_order: idx + 1,
      })),
    };

    try {
      await API.post(`/courses/${courseId}/reorder`, payload);
      setSections(newSections);
    } catch {
      loadCurriculum();
    }
  };

  // Lesson Handlers
  const openAddLesson = (secId: number) => {
    setTargetSectionId(secId);
    setEditingLesson(null);
    setLessonTitle('');
    setLessonDuration('10 min');
    setLessonType('video');
    setLessonSort(0);
    setLessonIsPublished(true);
    setVideoUrl('');
    setLessonContent('');
    setDocumentUrl('');
    setDocumentTitle('');
    setShowLessonModal(true);
  };

  const openEditLesson = (lesson: Lesson, secId: number) => {
    setTargetSectionId(secId);
    setEditingLesson(lesson);
    setLessonTitle(lesson.title);
    setLessonDuration(lesson.duration || '10 min');
    setLessonType(lesson.type);
    setLessonSort(lesson.sort_order || 0);
    setLessonIsPublished(lesson.is_published !== false);
    setVideoUrl(lesson.metadata?.video_url || '');
    setLessonContent(lesson.metadata?.content || lesson.description || '');
    setDocumentUrl(lesson.metadata?.document_url || '');
    setDocumentTitle(lesson.metadata?.document_title || '');
    setShowLessonModal(true);
  };

  const handleSaveLesson = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!courseId || !targetSectionId || !lessonTitle.trim()) return;

    setSaving(true);
    setError('');

    const payload = {
      title: lessonTitle,
      duration: lessonDuration,
      type: lessonType,
      sort_order: lessonSort,
      is_published: lessonIsPublished,
      video_url: videoUrl || undefined,
      content: lessonContent || undefined,
      document_url: documentUrl || undefined,
      document_title: documentTitle || undefined,
    };

    try {
      if (editingLesson) {
        await API.put(
          `/courses/${courseId}/sections/${targetSectionId}/lessons/${editingLesson.id}`,
          payload
        );
        setSuccessMsg('Lesson updated successfully.');
      } else {
        await API.post(
          `/courses/${courseId}/sections/${targetSectionId}/lessons`,
          payload
        );
        setSuccessMsg('New lesson created successfully.');
      }
      setShowLessonModal(false);
      loadCurriculum();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to save lesson.');
    } finally {
      setSaving(false);
    }
  };

  const handleTogglePublishLesson = async (secId: number, lesId: number) => {
    if (!courseId) return;
    try {
      await API.post(`/courses/${courseId}/sections/${secId}/lessons/${lesId}/toggle-publish`);
      setSuccessMsg('Lesson publishing status updated.');
      loadCurriculum();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to toggle lesson status.');
    }
  };

  const handleDeleteLesson = async (secId: number, lesId: number) => {
    if (!courseId || !window.confirm('Are you sure you want to delete this lesson?')) return;
    try {
      await API.delete(`/courses/${courseId}/sections/${secId}/lessons/${lesId}`);
      setSuccessMsg('Lesson deleted.');
      loadCurriculum();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to delete lesson.');
    }
  };

  const handleMoveLesson = async (secId: number, lessonIndex: number, direction: 'up' | 'down') => {
    if (!courseId) return;
    const section = sections.find((s) => s.id === secId);
    if (!section || !section.lessons) return;

    const targetIndex = direction === 'up' ? lessonIndex - 1 : lessonIndex + 1;
    if (targetIndex < 0 || targetIndex >= section.lessons.length) return;

    const newLessons = [...section.lessons];
    const temp = newLessons[lessonIndex];
    newLessons[lessonIndex] = newLessons[targetIndex];
    newLessons[targetIndex] = temp;

    const payload = {
      lessons: newLessons.map((l, idx) => ({
        id: l.id,
        section_id: secId,
        sort_order: idx + 1,
      })),
    };

    try {
      await API.post(`/courses/${courseId}/reorder`, payload);
      loadCurriculum();
    } catch {
      loadCurriculum();
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <p className="text-slate-500">Loading curriculum editor...</p>
      </div>
    );
  }

  return (
    <main className="min-h-screen bg-slate-50 py-10 px-4 sm:px-6 lg:px-8">
      <div className="max-w-6xl mx-auto">
        {/* Top Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
          <div>
            <Link
              to="/admin"
              className="text-xs font-bold text-blue-600 hover:text-blue-800 transition"
            >
              ← Back to Course Catalog
            </Link>
            <h1 className="text-3xl font-extrabold text-slate-900 mt-2">
              Curriculum Builder: {course?.title}
            </h1>
            <p className="text-sm text-slate-500 mt-1">
              Structure modules, video lessons, quizzes, and practical assignments.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <Link
              to={`/student/courses/${courseId}/lessons`}
              className="px-4 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-100 transition shadow-sm"
            >
              Preview Player 🚀
            </Link>
            <button
              type="button"
              onClick={() => {
                setEditingSection(null);
                setSectionTitle('');
                setSectionSort(sections.length);
                setShowSectionModal(true);
              }}
              className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition shadow"
            >
              + Add Section Module
            </button>
          </div>
        </div>

        {/* Notifications */}
        {error && <div className="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-700">{error}</div>}
        {successMsg && <div className="mb-6 rounded-2xl bg-green-50 p-4 text-sm text-green-700 font-bold">✓ {successMsg}</div>}

        {/* Sections List */}
        {sections.length === 0 ? (
          <div className="bg-white p-12 rounded-3xl ring-1 ring-slate-200 text-center">
            <p className="text-lg font-bold text-slate-700">No curriculum modules created yet.</p>
            <p className="text-sm text-slate-500 mt-1 mb-6">Start by creating the first section module for this course.</p>
            <button
              type="button"
              onClick={() => setShowSectionModal(true)}
              className="px-6 py-2.5 rounded-xl font-bold text-xs text-white bg-blue-600 hover:bg-blue-700"
            >
              Create Section 1
            </button>
          </div>
        ) : (
          <div className="space-y-6">
            {sections.map((section, sIdx) => {
              const lessons = section.lessons || [];
              return (
                <div
                  key={section.id}
                  className="bg-white rounded-3xl ring-1 ring-slate-200 shadow-sm overflow-hidden"
                >
                  {/* Section Header */}
                  <div className="bg-slate-50/80 px-6 py-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-4">
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                          Module {sIdx + 1}
                        </span>
                        <span
                          className={`text-[9px] font-black uppercase px-2 py-0.5 rounded-md ${
                            section.is_published !== false
                              ? 'bg-emerald-100 text-emerald-700'
                              : 'bg-slate-200 text-slate-600'
                          }`}
                        >
                          {section.is_published !== false ? 'Published' : 'Draft'}
                        </span>
                      </div>
                      <h2 className="text-lg font-bold text-slate-900">{section.title}</h2>
                    </div>

                    <div className="flex items-center gap-2 text-xs font-bold">
                      <button
                        type="button"
                        disabled={sIdx === 0}
                        onClick={() => handleMoveSection(sIdx, 'up')}
                        className="px-2 py-1.5 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                        title="Move Module Up"
                      >
                        ↑
                      </button>
                      <button
                        type="button"
                        disabled={sIdx === sections.length - 1}
                        onClick={() => handleMoveSection(sIdx, 'down')}
                        className="px-2 py-1.5 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                        title="Move Module Down"
                      >
                        ↓
                      </button>
                      <button
                        type="button"
                        onClick={() => handleTogglePublishSection(section.id)}
                        className={`px-3 py-1.5 rounded-xl transition ${
                          section.is_published !== false
                            ? 'bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100'
                            : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100'
                        }`}
                      >
                        {section.is_published !== false ? 'Unpublish' : 'Publish'}
                      </button>
                      <button
                        type="button"
                        onClick={() => openAddLesson(section.id)}
                        className="px-3.5 py-1.5 rounded-lg text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 transition"
                      >
                        + Add Lesson
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setEditingSection(section);
                          setSectionTitle(section.title);
                          setSectionSort(section.sort_order);
                          setShowSectionModal(true);
                        }}
                        className="p-1.5 text-xs text-slate-500 hover:text-slate-900 rounded-lg hover:bg-slate-200/60"
                        title="Edit Section"
                      >
                        ✏️
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDeleteSection(section.id)}
                        className="p-1.5 text-xs text-red-500 hover:text-red-700 rounded-lg hover:bg-red-50"
                        title="Delete Section"
                      >
                        🗑️
                      </button>
                    </div>
                  </div>

                  {/* Lessons Table / List */}
                  {lessons.length === 0 ? (
                    <div className="p-6 text-center text-sm text-slate-400 italic">
                      No lessons in this module yet. Click "+ Add Lesson" to create one.
                    </div>
                  ) : (
                    <div className="divide-y divide-slate-100">
                      {lessons.map((les, lIdx) => (
                        <div
                          key={les.id}
                          className="px-6 py-3.5 flex items-center justify-between gap-4 hover:bg-slate-50/50 transition"
                        >
                          <div className="flex items-center gap-3 min-w-0">
                            <span className="text-base">
                              {les.type === 'video'
                                ? '📹'
                                : les.type === 'quiz'
                                ? '❓'
                                : les.type === 'assignment'
                                ? '📝'
                                : les.type === 'project'
                                ? '🏆'
                                : les.type === 'document'
                                ? '📑'
                                : les.type === 'article'
                                ? '📰'
                                : '📄'}
                            </span>
                            <div className="min-w-0">
                              <div className="flex items-center gap-2">
                                <p className="text-sm font-bold text-slate-800 truncate">{les.title}</p>
                                <span
                                  className={`text-[8px] font-black uppercase px-1.5 py-0.5 rounded ${
                                    les.is_published !== false
                                      ? 'bg-emerald-100 text-emerald-700'
                                      : 'bg-slate-200 text-slate-600'
                                  }`}
                                >
                                  {les.is_published !== false ? 'Published' : 'Draft'}
                                </span>
                              </div>
                              <div className="flex items-center gap-2 mt-0.5">
                                <span className="text-[10px] uppercase font-bold text-slate-400">
                                  {les.type}
                                </span>
                                {les.duration && (
                                  <span className="text-[10px] text-slate-400">
                                    • {les.duration}
                                  </span>
                                )}
                                {les.metadata?.video_url && (
                                  <span className="text-[10px] text-blue-500 truncate max-w-[200px]">
                                    🎥 {les.metadata.video_url}
                                  </span>
                                )}
                              </div>
                            </div>
                          </div>

                          <div className="flex items-center gap-2 flex-shrink-0 font-bold text-xs">
                            <button
                              type="button"
                              disabled={lIdx === 0}
                              onClick={() => handleMoveLesson(section.id, lIdx, 'up')}
                              className="px-2 py-1 rounded bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                              title="Move Lesson Up"
                            >
                              ↑
                            </button>
                            <button
                              type="button"
                              disabled={lIdx === lessons.length - 1}
                              onClick={() => handleMoveLesson(section.id, lIdx, 'down')}
                              className="px-2 py-1 rounded bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-30 disabled:cursor-not-allowed transition"
                              title="Move Lesson Down"
                            >
                              ↓
                            </button>
                            <button
                              type="button"
                              onClick={() => handleTogglePublishLesson(section.id, les.id)}
                              className={`px-2 py-1 rounded-lg text-[11px] transition ${
                                les.is_published !== false
                                  ? 'bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100'
                                  : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100'
                              }`}
                            >
                              {les.is_published !== false ? 'Draft' : 'Publish'}
                            </button>
                            <button
                              type="button"
                              onClick={() => openEditLesson(les, section.id)}
                              className="px-3 py-1 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg"
                            >
                              Edit
                            </button>
                            <button
                              type="button"
                              onClick={() => handleDeleteLesson(section.id, les.id)}
                              className="px-3 py-1 text-xs font-semibold text-red-600 bg-red-50 hover:bg-red-100 rounded-lg"
                            >
                              Delete
                            </button>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Section Modal */}
      {showSectionModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 flex items-center justify-center p-4">
          <form
            onSubmit={handleSaveSection}
            className="w-full max-w-md bg-white rounded-3xl p-6 shadow-2xl space-y-4"
          >
            <h3 className="text-lg font-bold text-slate-900">
              {editingSection ? 'Edit Module Section' : 'Create New Module Section'}
            </h3>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Section Title</label>
              <input
                type="text"
                value={sectionTitle}
                onChange={(e) => setSectionTitle(e.target.value)}
                placeholder="e.g. Module 1: Introduction & Fundamentals"
                className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none"
                required
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Sort Order</label>
              <input
                type="number"
                value={sectionSort}
                onChange={(e) => setSectionSort(Number(e.target.value))}
                className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none"
              />
            </div>

            <div className="flex justify-end gap-3 pt-3">
              <button
                type="button"
                onClick={() => setShowSectionModal(false)}
                className="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={saving}
                className="px-6 py-2 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl disabled:opacity-50"
              >
                {saving ? 'Saving...' : 'Save Section'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Lesson Modal */}
      {showLessonModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/50 flex items-center justify-center p-4">
          <form
            onSubmit={handleSaveLesson}
            className="w-full max-w-lg bg-white rounded-3xl p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto"
          >
            <h3 className="text-lg font-bold text-slate-900">
              {editingLesson ? 'Edit Lesson' : 'Create New Lesson'}
            </h3>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Lesson Title</label>
              <input
                type="text"
                value={lessonTitle}
                onChange={(e) => setLessonTitle(e.target.value)}
                placeholder="e.g. Masterclass on State Management"
                className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none"
                required
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Lesson Type</label>
                <select
                  value={lessonType}
                  onChange={(e) => setLessonType(e.target.value as LessonType)}
                  className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none bg-white"
                >
                  <option value="video">📹 Video Lesson</option>
                  <option value="text">📄 Text Lesson</option>
                  <option value="article">📰 Article Lesson</option>
                  <option value="document">📁 Document / File</option>
                  <option value="quiz">❓ Interactive Quiz</option>
                  <option value="assignment">📝 Practical Assignment</option>
                  <option value="project">🏆 Capstone Project</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Duration</label>
                <input
                  type="text"
                  value={lessonDuration}
                  onChange={(e) => setLessonDuration(e.target.value)}
                  placeholder="e.g. 15 min"
                  className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none"
                />
              </div>
            </div>

            {lessonType === 'video' && (
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Video Stream / YouTube URL</label>
                <input
                  type="url"
                  value={videoUrl}
                  onChange={(e) => setVideoUrl(e.target.value)}
                  placeholder="https://www.youtube.com/watch?v=..."
                  className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none"
                />
              </div>
            )}

            {lessonType === 'document' && (
              <div className="space-y-3">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Document Title / Name</label>
                  <input
                    type="text"
                    value={documentTitle}
                    onChange={(e) => setDocumentTitle(e.target.value)}
                    placeholder="e.g. Module Summary PDF"
                    className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Document URL / Storage Path</label>
                  <input
                    type="text"
                    value={documentUrl}
                    onChange={(e) => setDocumentUrl(e.target.value)}
                    placeholder="https://... or /documents/..."
                    className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none"
                  />
                </div>
              </div>
            )}

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                {lessonType === 'text' ? 'Article Content / Markdown' : 'Reading Content / Instructions'}
              </label>
              <textarea
                rows={lessonType === 'text' ? 8 : 4}
                value={lessonContent}
                onChange={(e) => setLessonContent(e.target.value)}
                placeholder="Enter detailed content, key takeaways, or starter instructions..."
                className="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-blue-600 outline-none font-mono"
              />
            </div>

            <div className="flex items-center gap-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
              <input
                type="checkbox"
                id="adminLessonPublishedToggle"
                checked={lessonIsPublished}
                onChange={(e) => setLessonIsPublished(e.target.checked)}
                className="w-4 h-4 text-blue-600 rounded"
              />
              <label htmlFor="adminLessonPublishedToggle" className="text-xs font-bold text-slate-700 cursor-pointer">
                Publish Lesson immediately (uncheck to save as Draft)
              </label>
            </div>

            <div className="flex justify-end gap-3 pt-3">
              <button
                type="button"
                onClick={() => setShowLessonModal(false)}
                className="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={saving}
                className="px-6 py-2 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl disabled:opacity-50"
              >
                {saving ? 'Saving...' : editingLesson ? 'Update Lesson' : 'Create Lesson'}
              </button>
            </div>
          </form>
        </div>
      )}
    </main>
  );
}
