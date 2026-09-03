import { useState } from 'react'
import API from '../../services/api'
import MediaPickerModal, { type MediaItem } from './MediaPickerModal'

interface ImageUploadFieldProps {
  label: string
  value?: string | null
  onChange: (url: string, mediaId?: number) => void
  altText?: string | null
  onAltChange?: (alt: string) => void
  folder?: string
  aspectRatio?: 'square' | 'video' | 'banner' | 'avatar'
  helpText?: string
  placeholder?: string
  required?: boolean
}

export default function ImageUploadField({
  label,
  value,
  onChange,
  altText,
  onAltChange,
  folder = 'general',
  aspectRatio = 'video',
  helpText,
  placeholder,
  required = false,
}: ImageUploadFieldProps) {
  const [modalOpen, setModalOpen] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')

  const handleDirectUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return

    setUploading(true)
    setError('')

    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder', folder)
    formData.append('title', file.name.replace(/\.[^/.]+$/, ''))
    if (altText) formData.append('alt_text', altText)

    try {
      const res = await API.post<{ media: MediaItem }>('/admin/media', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      if (res.data?.media?.url) {
        onChange(res.data.media.url, res.data.media.id)
        if (onAltChange && !altText) {
          onAltChange(file.name.replace(/\.[^/.]+$/, ''))
        }
      }
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setError(msg || 'Upload failed. Allowed formats: JPG, PNG, WebP, SVG.')
    } finally {
      setUploading(false)
      e.target.value = ''
    }
  }

  const handleSelectFromLibrary = (media: MediaItem) => {
    onChange(media.url, media.id)
    if (onAltChange && media.alt_text) {
      onAltChange(media.alt_text)
    }
  }

  const handleRemove = () => {
    onChange('')
  }

  // Aspect ratio styling
  const aspectClass =
    aspectRatio === 'square'
      ? 'w-32 h-32'
      : aspectRatio === 'avatar'
      ? 'w-24 h-24 rounded-full'
      : aspectRatio === 'banner'
      ? 'w-full h-36'
      : 'w-full h-44'

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <label className="block text-xs font-bold text-slate-700">
          {label} {required && <span className="text-red-500">*</span>}
        </label>
        {value && (
          <span className="text-[11px] text-emerald-600 font-semibold flex items-center gap-1">
            ✓ Image configured
          </span>
        )}
      </div>

      {/* Image Preview & Controls Box */}
      <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl space-y-3">
        <div className="flex flex-col sm:flex-row gap-4 items-start">
          {/* Visual Preview */}
          <div
            className={`${aspectClass} bg-slate-200/80 rounded-xl border border-slate-300/80 overflow-hidden shrink-0 relative flex items-center justify-center`}
          >
            {value ? (
              <img
                src={value}
                alt={altText || label}
                className="w-full h-full object-cover"
                onError={(e) => {
                  ;(e.target as HTMLImageElement).src =
                    placeholder ||
                    'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=400&q=80'
                }}
              />
            ) : (
              <div className="text-center p-3 text-slate-400">
                <span className="text-2xl block mb-1">🖼️</span>
                <span className="text-[10px] font-bold uppercase tracking-wider">No Image</span>
              </div>
            )}

            {uploading && (
              <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center text-white text-xs font-bold">
                Uploading...
              </div>
            )}
          </div>

          {/* Action Buttons & URL Input */}
          <div className="flex-1 space-y-2.5 w-full">
            <div className="flex flex-wrap items-center gap-2">
              {/* Choose from Media Library */}
              <button
                type="button"
                onClick={() => setModalOpen(true)}
                className="px-3.5 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold transition shadow-xs cursor-pointer flex items-center gap-1.5"
              >
                <span>📂 Choose from Library</span>
              </button>

              {/* Direct Upload New */}
              <label className="px-3.5 py-1.5 rounded-xl bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 text-xs font-bold transition cursor-pointer flex items-center gap-1.5">
                <span>⬆️ Upload New</span>
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/svg+xml,image/gif"
                  onChange={handleDirectUpload}
                  disabled={uploading}
                  className="hidden"
                />
              </label>

              {/* Remove */}
              {value && (
                <button
                  type="button"
                  onClick={handleRemove}
                  className="px-3 py-1.5 rounded-xl bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 text-xs font-bold transition cursor-pointer"
                >
                  ✕ Remove
                </button>
              )}
            </div>

            {/* Direct URL input field */}
            <div>
              <input
                type="text"
                value={value || ''}
                onChange={(e) => onChange(e.target.value)}
                placeholder="Or paste external image URL (https://...)"
                className="w-full px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-xs text-slate-800 placeholder:text-slate-400 focus:outline-hidden focus:ring-1 focus:ring-blue-500 font-mono"
              />
            </div>

            {/* Optional Alt Text Field */}
            {onAltChange && (
              <div>
                <input
                  type="text"
                  value={altText || ''}
                  onChange={(e) => onAltChange(e.target.value)}
                  placeholder="Image Alt Text (for accessibility & SEO)..."
                  className="w-full px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-xs text-slate-700 placeholder:text-slate-400 focus:outline-hidden focus:ring-1 focus:ring-blue-500"
                />
              </div>
            )}
          </div>
        </div>

        {error && <p className="text-xs text-red-600 font-medium">{error}</p>}
        {helpText && <p className="text-[11px] text-slate-500">{helpText}</p>}
      </div>

      {/* Media Picker Modal */}
      <MediaPickerModal
        isOpen={modalOpen}
        onClose={() => setModalOpen(false)}
        onSelect={handleSelectFromLibrary}
        folder={folder}
        title={`Select Image for ${label}`}
      />
    </div>
  )
}
