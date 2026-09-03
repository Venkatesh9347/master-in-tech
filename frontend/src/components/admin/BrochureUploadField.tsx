import { useState } from 'react'
import API from '../../services/api'
import MediaPickerModal, { type MediaItem } from './MediaPickerModal'

interface BrochureUploadFieldProps {
  label: string
  value?: string | null
  mediaId?: number | null
  onChange: (url: string, mediaId?: number) => void
  helpText?: string
}

export default function BrochureUploadField({
  label,
  value,
  onChange,
  helpText = 'Official course brochure & syllabus outline (PDF format, max 50MB).',
}: BrochureUploadFieldProps) {
  const [pickerOpen, setPickerOpen] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return

    if (!file.name.toLowerCase().endsWith('.pdf') && file.type !== 'application/pdf') {
      setError('Only PDF documents are accepted for course brochures.')
      return
    }

    setUploading(true)
    setError('')

    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder', 'courses')
    formData.append('title', file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ') + ' Brochure')
    formData.append('alt_text', file.name)

    try {
      const res = await API.post<{ media: MediaItem }>('/admin/media', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      onChange(res.data.media.url, res.data.media.id)
    } catch {
      setError('Failed to upload brochure. Please try again.')
    } finally {
      setUploading(false)
      // reset file input
      e.target.value = ''
    }
  }

  const handleSelectFromLibrary = (asset: MediaItem) => {
    onChange(asset.url, asset.id)
  }

  const handleRemove = () => {
    onChange('', undefined)
  }

  const filename = value ? value.split('/').pop() || 'Course_Brochure.pdf' : ''

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <label className="block text-slate-400 font-bold uppercase text-[10px]">
          {label}
        </label>
        {value && (
          <span className="text-[10px] text-emerald-400 font-bold flex items-center gap-1">
            <span>✓</span> PDF Attached
          </span>
        )}
      </div>

      {/* Brochure Status Box */}
      <div className="p-3.5 bg-slate-900 rounded-2xl border border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center gap-3 min-w-0">
          <div className={`w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0 border ${
            value
              ? 'bg-red-950/80 text-red-400 border-red-800/80 shadow-xs'
              : 'bg-slate-950 text-slate-500 border-slate-800'
          }`}>
            📄
          </div>
          <div className="min-w-0">
            {value ? (
              <>
                <p className="text-xs font-bold text-white truncate max-w-xs sm:max-w-md">
                  {filename}
                </p>
                <p className="text-[10px] text-slate-400 truncate max-w-xs sm:max-w-md">
                  {value}
                </p>
              </>
            ) : (
              <>
                <p className="text-xs font-bold text-slate-400">No brochure attached</p>
                <p className="text-[10px] text-slate-500">Upload a PDF brochure for public visitors to download.</p>
              </>
            )}
          </div>
        </div>

        {/* Action buttons */}
        <div className="flex items-center gap-2 shrink-0">
          {value && (
            <a
              href={value}
              target="_blank"
              rel="noopener noreferrer"
              className="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold transition flex items-center gap-1"
            >
              <span>👁️</span> Preview
            </a>
          )}

          <button
            type="button"
            onClick={() => setPickerOpen(true)}
            className="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold transition"
          >
            📚 Media Library
          </button>

          <label className="px-3 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold cursor-pointer transition flex items-center gap-1">
            <span>{uploading ? '⏳' : '⬆️'}</span>
            <span>{uploading ? 'Uploading...' : value ? 'Replace PDF' : 'Upload PDF'}</span>
            <input
              type="file"
              accept=".pdf,application/pdf"
              className="hidden"
              onChange={handleFileUpload}
              disabled={uploading}
            />
          </label>

          {value && (
            <button
              type="button"
              onClick={handleRemove}
              className="px-2.5 py-1.5 rounded-xl bg-red-950/60 hover:bg-red-900/80 text-red-300 text-xs font-bold border border-red-800/80 transition"
              title="Remove brochure"
            >
              ✕
            </button>
          )}
        </div>
      </div>

      {error && (
        <p className="text-[11px] text-red-400 font-medium">⚠️ {error}</p>
      )}

      {/* Manual URL Input */}
      <div className="flex items-center gap-2">
        <input
          type="text"
          value={value || ''}
          onChange={(e) => onChange(e.target.value)}
          placeholder="https://.../brochure.pdf or /storage/uploads/media/courses/..."
          className="w-full px-3 py-1.5 rounded-xl bg-slate-950 border border-slate-800 text-slate-300 text-xs outline-none focus:border-purple-500 font-mono"
        />
      </div>

      {helpText && (
        <p className="text-[10px] text-slate-500">{helpText}</p>
      )}

      {/* Media Picker Dialog */}
      {pickerOpen && (
        <MediaPickerModal
          isOpen={pickerOpen}
          onClose={() => setPickerOpen(false)}
          onSelect={handleSelectFromLibrary}
          folder="courses"
          title="Select Course Brochure PDF"
        />
      )}
    </div>
  )
}
