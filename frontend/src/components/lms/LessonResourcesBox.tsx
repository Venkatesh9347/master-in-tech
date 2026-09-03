import type { LessonResource } from '../../types/lms';

interface LessonResourcesBoxProps {
  resources: LessonResource[];
}

export default function LessonResourcesBox({ resources }: LessonResourcesBoxProps) {
  if (!resources || resources.length === 0) return null;

  return (
    <div className="bg-white p-6 rounded-2xl ring-1 ring-slate-200 shadow-sm mt-6">
      <div className="flex items-center gap-2 mb-4">
        <span className="text-xl">📎</span>
        <h3 className="text-lg font-bold text-slate-900">Lesson Attachments & Resources</h3>
      </div>

      <div className="divide-y divide-slate-100">
        {resources.map((res) => (
          <div
            key={res.id}
            className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
          >
            <div>
              <p className="text-sm font-bold text-slate-800">{res.title}</p>
              {res.description && (
                <p className="text-xs text-slate-500 mt-0.5">{res.description}</p>
              )}
            </div>

            <div className="flex items-center gap-3">
              {res.file_size && (
                <span className="text-xs text-slate-400 font-medium">
                  {res.file_size}
                </span>
              )}
              <a
                href={res.file_url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-100 transition"
              >
                <span>Download</span>
                <span>↓</span>
              </a>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
