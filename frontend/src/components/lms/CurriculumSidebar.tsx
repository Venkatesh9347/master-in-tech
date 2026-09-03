import { useState } from 'react';
import type { Section, Lesson } from '../../types/lms';

interface CurriculumSidebarProps {
  sections: Section[];
  activeLessonId: number | null;
  completedLessonIds: number[];
  onSelectLesson: (lesson: Lesson, section: Section) => void;
  isOpenMobile?: boolean;
  onCloseMobile?: () => void;
}

export default function CurriculumSidebar({
  sections,
  activeLessonId,
  completedLessonIds,
  onSelectLesson,
  isOpenMobile = false,
  onCloseMobile,
}: CurriculumSidebarProps) {
  // Keep all sections open by default
  const [openSections, setOpenSections] = useState<Record<number, boolean>>(() => {
    const initial: Record<number, boolean> = {};
    sections.forEach((sec) => {
      initial[sec.id] = true;
    });
    return initial;
  });

  const toggleSection = (sectionId: number) => {
    setOpenSections((prev) => ({
      ...prev,
      [sectionId]: !prev[sectionId],
    }));
  };

  const getLessonIcon = (type: string) => {
    switch (type) {
      case 'video':
        return '📹';
      case 'text':
        return '📄';
      case 'document':
        return '📁';
      case 'quiz':
        return '❓';
      case 'assignment':
        return '📝';
      default:
        return '📖';
    }
  };

  const content = (
    <div className="flex flex-col h-full bg-white border-l border-slate-200">
      {/* Header */}
      <div className="p-4 border-b border-slate-200 flex items-center justify-between">
        <div>
          <h2 className="text-base font-bold text-slate-900">Course Curriculum</h2>
          <p className="text-xs text-slate-500 mt-0.5">
            {sections.reduce((acc, s) => acc + (s.lessons?.length || 0), 0)} lessons across {sections.length} modules
          </p>
        </div>
        {onCloseMobile && (
          <button
            type="button"
            onClick={onCloseMobile}
            className="md:hidden p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100"
          >
            ✕
          </button>
        )}
      </div>

      {/* Sections Accordion */}
      <div className="flex-1 overflow-y-auto divide-y divide-slate-100 p-2">
        {sections.map((section, sIdx) => {
          const isOpen = openSections[section.id] ?? true;
          const sectionLessons = section.lessons || [];
          const sectionCompletedCount = sectionLessons.filter((l) =>
            completedLessonIds.includes(l.id)
          ).length;

          return (
            <div key={section.id} className="py-2">
              {/* Section Header */}
              <button
                type="button"
                onClick={() => toggleSection(section.id)}
                className="w-full flex items-center justify-between p-2 rounded-lg text-left hover:bg-slate-50 transition group"
              >
                <div className="flex-1 pr-2">
                  <p className="text-xs font-semibold uppercase tracking-wider text-slate-400">
                    Module {sIdx + 1}
                  </p>
                  <h3 className="text-sm font-bold text-slate-800 group-hover:text-blue-600 transition">
                    {section.title}
                  </h3>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs font-medium text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">
                    {sectionCompletedCount}/{sectionLessons.length}
                  </span>
                  <span className="text-xs text-slate-400 transform transition-transform duration-200">
                    {isOpen ? '▲' : '▼'}
                  </span>
                </div>
              </button>

              {/* Lessons List */}
              {isOpen && (
                <div className="mt-1 space-y-1 pl-2">
                  {sectionLessons.map((lesson) => {
                    const isActive = activeLessonId === lesson.id;
                    const isCompleted = completedLessonIds.includes(lesson.id);

                    return (
                      <button
                        key={lesson.id}
                        type="button"
                        onClick={() => {
                          onSelectLesson(lesson, section);
                          if (onCloseMobile) onCloseMobile();
                        }}
                        className={`w-full flex items-center justify-between p-2.5 rounded-xl text-left transition text-xs font-medium ${
                          isActive
                            ? 'bg-blue-50 text-blue-700 font-bold shadow-sm ring-1 ring-blue-200'
                            : 'text-slate-700 hover:bg-slate-50'
                        }`}
                      >
                        <div className="flex items-center gap-2.5 min-w-0 pr-2">
                          <span className="text-base flex-shrink-0">
                            {getLessonIcon(lesson.type)}
                          </span>
                          <span className="truncate">{lesson.title}</span>
                        </div>

                        <div className="flex items-center gap-2 flex-shrink-0">
                          {lesson.duration && (
                            <span className="text-[10px] text-slate-400 font-normal">
                              {lesson.duration}
                            </span>
                          )}
                          {isCompleted ? (
                            <span
                              title="Completed"
                              className="h-4 w-4 rounded-full bg-green-100 text-green-700 flex items-center justify-center text-[10px] font-black"
                            >
                              ✓
                            </span>
                          ) : (
                            <span
                              title="Incomplete"
                              className="h-4 w-4 rounded-full border border-slate-300 flex items-center justify-center text-[10px]"
                            />
                          )}
                        </div>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );

  return (
    <>
      {/* Desktop Sidebar */}
      <aside className="hidden md:block w-80 h-full flex-shrink-0">
        {content}
      </aside>

      {/* Mobile Drawer */}
      {isOpenMobile && (
        <div className="fixed inset-0 z-50 md:hidden bg-slate-900/50 backdrop-blur-sm flex justify-end">
          <div className="w-80 h-full bg-white shadow-xl animate-in slide-in-from-right">
            {content}
          </div>
        </div>
      )}
    </>
  );
}
