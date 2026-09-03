import type { Lesson } from '../../types/lms';

interface TextReaderProps {
  lesson: Lesson;
}

export default function TextReader({ lesson }: TextReaderProps) {
  const content = lesson.metadata?.content || lesson.description || 'No detailed content provided for this reading lesson.';

  return (
    <div className="flex flex-col space-y-6">
      <div className="bg-white p-8 rounded-2xl ring-1 ring-slate-200 shadow-sm">
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4 pb-4 border-b border-slate-100">
          <div className="flex items-center gap-2">
            <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
              {lesson.type === 'document' ? '📁 Document Resource' : '📄 Reading Material'}
            </span>
            {lesson.duration && (
              <span className="text-xs text-slate-500 font-semibold">
                📖 {lesson.duration} read
              </span>
            )}
          </div>
        </div>

        <h1 className="text-3xl font-extrabold text-slate-900 mb-6">
          {lesson.title}
        </h1>

        {/* Document Resource Box if type === document */}
        {lesson.type === 'document' && lesson.metadata?.document_url && (
          <div className="mb-6 p-6 rounded-2xl bg-indigo-50 border border-indigo-200 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <span className="text-3xl">📑</span>
              <div>
                <h4 className="text-sm font-bold text-indigo-950">
                  {lesson.metadata.document_title || lesson.title}
                </h4>
                <p className="text-xs text-indigo-700">Official course document and reference file.</p>
              </div>
            </div>
            <a
              href={lesson.metadata.document_url}
              target="_blank"
              rel="noopener noreferrer"
              className="px-5 py-2.5 rounded-xl font-bold text-xs bg-indigo-600 hover:bg-indigo-700 text-white transition shadow-sm flex items-center gap-2"
            >
              <span>↓</span> Access Document
            </a>
          </div>
        )}

        {/* Content Body */}
        <div className="prose prose-slate max-w-none text-slate-700 text-base leading-relaxed space-y-4">
          <div className="whitespace-pre-line bg-slate-50 p-6 rounded-xl border border-slate-100 font-normal">
            {content}
          </div>
        </div>

        {/* Key Takeaways Card */}
        <div className="mt-8 rounded-xl bg-blue-50 p-6 border border-blue-100">
          <h3 className="text-sm font-bold text-blue-900 uppercase tracking-wider mb-2 flex items-center gap-2">
            💡 Key Takeaway
          </h3>
          <p className="text-sm text-blue-800 leading-relaxed">
            Ensure you thoroughly review all documentation and sample code snippets provided in this section before continuing to the assessments.
          </p>
        </div>
      </div>
    </div>
  );
}
