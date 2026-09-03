import React, { useEffect, useState, useCallback } from 'react'
import API from '../../services/api'

export interface MediaItem {
  id: number
  file_name: string
  original_name?: string | null
  title?: string | null
  file_path: string
  url: string
  folder?: string
  mime_type?: string | null
  file_size?: number
  alt_text?: string | null
  created_at?: string
}

interface MediaPickerModalProps {
  isOpen: boolean
  onClose: () => void
  onSelect: (media: MediaItem) => void
  folder?: string
  title?: string
}

const FOLDER_TABS = [
  { key: 'all', label: 'All Media' },
  { key: 'courses', label: 'Courses' },
  { key: 'instructors', label: 'Instructors' },
  { key: 'events', label: 'Events' },
  { key: 'testimonials', label: 'Testimonials' },
  { key: 'resources', label: 'Resources' },
  { key: 'branding', label: 'Branding & Logo' },
  { key: 'general', label: 'General' },
]

export default function MediaPickerModal({
  isOpen,
  onClose,
  onSelect,
  folder = 'all',
  title = 'Select Media Asset',
}: MediaPickerModalProps) {
  const [activeFolder, setActiveFolder] = useState(folder)
  const [search, setSearch] = useState('')
  const [mediaList, setMediaList] = useState<MediaItem[]>([])
  const [loading, setLoading] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState('')
  const [selectedMedia, setSelectedMedia] = useState<MediaItem | null>(null)

  const fetchMedia = useCallback(() => {
    setLoading(true)
    const params: Record<string, string | number> = { per_page: 48 }
    if (activeFolder !== 'all') params.folder = activeFolder
    if (search.trim()) params.search = search.trim()

    API.get<{ data?: MediaItem[] } | MediaItem[]>('/admin/media', { params })
      .then((res) => {
        const raw = res.data
        const items = Array.isArray(raw) ? raw : (raw as { data?: MediaItem[] }).data || []
        setMediaList(items)
      })
      .catch(() => setMediaList([]))
      .finally(() => setLoading(false))
  }, [activeFolder, search])

  useEffect(() => {
    if (isOpen) {
      setActiveFolder(folder)
      setSelectedMedia(null)
    }
  }, [isOpen, folder])

  useEffect(() => {
    if (isOpen) {
      fetchMedia()
    }
  }, [isOpen, fetchMedia])

  if (!isOpen) return null

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    fetchMedia()
  }

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return

    setUploading(true)
    setUploadError('')

    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder', activeFolder !== 'all' ? activeFolder : 'general')
    formData.append('title', file.name.replace(/\.[^/.]+$/, ''))
    formData.append('alt_text', file.name.replace(/\.[^/.]+$/, ''))

    try {
      const res = await API.post<{ media: MediaItem }>('/admin/media', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      if (res.data?.media) {
        setMediaList((prev) => [res.data.media, ...prev])
        setSelectedMedia(res.data.media)
      }
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setUploadError(msg || 'Failed to upload media. Please ensure image format is JPG, PNG, WebP, or SVG.')
    } finally {
      setUploading(false)
      e.target.value = ''
    }
  }

  const handleConfirmSelect = () => {
    if (selectedMedia) {
      onSelect(selectedMedia)
      onClose()
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-xs animate-in fade-in duration-200">
      <div className="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-5xl h-[85vh] flex flex-col overflow-hidden">
        {/* Modal Header */}
        <div className="p-6 border-b border-slate-100 flex items-center justify-between shrink-0">
          <div>
            <h2 className="text-lg font-black text-slate-900">{title}</h2>
            <p className="text-xs text-slate-500 mt-0.5">
              Choose an existing asset from the library or upload a new file.
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 font-bold flex items-center justify-center text-sm transition"
          >
            ✕
          </button>
        </div>

        {/* Toolbar: Folder tabs, search, and direct upload */}
        <div className="px-6 py-3 border-b border-slate-100 bg-slate-50 flex flex-wrap items-center justify-between gap-3 shrink-0">
          {/* Folder tabs */}
          <div className="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1">
            {FOLDER_TABS.map((tab) => (
              <button
                key={tab.key}
                type="button"
                onClick={() => setActiveFolder(tab.key)}
                className={`px-3 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap cursor-pointer ${
                  activeFolder === tab.key
                    ? 'bg-blue-600 text-white shadow-xs'
                    : 'bg-white text-slate-600 hover:bg-slate-200/70 border border-slate-200'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          {/* Search + Upload */}
          <div className="flex items-center gap-2">
            <form onSubmit={handleSearchSubmit} className="flex items-center gap-1">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search media..."
                className="px-3 py-1.5 rounded-xl border border-slate-200 text-xs bg-white focus:outline-hidden focus:ring-1 focus:ring-blue-500 w-44"
              />
              <button
                type="submit"
                className="px-3 py-1.5 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition"
              >
                🔍
              </button>
            </form>

            <label className="px-3.5 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xs transition shrink-0">
              <span>{uploading ? 'Uploading...' : '+ Upload New'}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp,image/svg+xml,image/gif"
                onChange={handleFileUpload}
                disabled={uploading}
                className="hidden"
              />
            </label>
          </div>
        </div>

        {uploadError && (
          <div className="px-6 py-2 bg-red-50 text-red-600 text-xs font-medium border-b border-red-100">
            {uploadError}
          </div>
        )}

        {/* Media Grid */}
        <div className="flex-1 p-6 overflow-y-auto bg-slate-50/50">
          {loading ? (
            <div className="flex items-center justify-center h-full text-xs text-slate-400 font-medium">
              Loading media library assets...
            </div>
          ) : mediaList.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-full text-center p-8 space-y-3">
              <span className="text-4xl">🖼️</span>
              <p className="text-sm font-bold text-slate-700">No media assets found</p>
              <p className="text-xs text-slate-400 max-w-sm">
                Upload your first image for this category using the "+ Upload New" button above.
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
              {mediaList.map((item) => {
                const isSelected = selectedMedia?.id === item.id
                return (
                  <button
                    key={item.id}
                    type="button"
                    onClick={() => setSelectedMedia(item)}
                    onDoubleClick={() => {
                      onSelect(item)
                      onClose()
                    }}
                    className={`group relative rounded-2xl overflow-hidden border text-left flex flex-col bg-white transition cursor-pointer ${
                      isSelected
                        ? 'border-blue-600 ring-2 ring-blue-500/30 shadow-md'
                        : 'border-slate-200 hover:border-slate-300 hover:shadow-xs'
                    }`}
                  >
                    <div className="aspect-square bg-slate-100 overflow-hidden relative flex items-center justify-center">
                      <img
                        src={item.url}
                        alt={item.alt_text || item.title || item.file_name}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                        onError={(e) => {
                          ;(e.target as HTMLImageElement).src =
                            'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=400&q=80'
                        }}
                      />
                      {isSelected && (
                        <div className="absolute top-2 right-2 w-5 h-5 bg-blue-600 rounded-full text-white flex items-center justify-center text-[10px] font-bold shadow-xs">
                          ✓
                        </div>
                      )}
                    </div>
                    <div className="p-2.5">
                      <p className="text-[11px] font-bold text-slate-800 truncate" title={item.title || item.file_name}>
                        {item.title || item.file_name}
                      </p>
                      <p className="text-[9px] text-slate-400 truncate mt-0.5">
                        {item.folder || 'general'} •{' '}
                        {item.file_size ? `${Math.round(item.file_size / 1024)} KB` : ''}
                      </p>
                    </div>
                  </button>
                )
              })}
            </div>
          )}
        </div>

        {/* Modal Footer */}
        <div className="p-4 border-t border-slate-100 bg-white flex items-center justify-between shrink-0">
          <div className="text-xs text-slate-500 truncate max-w-md">
            {selectedMedia ? (
              <span>
                Selected:{' '}
                <strong className="text-slate-800">
                  {selectedMedia.title || selectedMedia.file_name}
                </strong>{' '}
                ({selectedMedia.url})
              </span>
            ) : (
              <span>Click an image to select, or double-click to confirm immediately.</span>
            )}
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition"
            >
              Cancel
            </button>
            <button
              type="button"
              disabled={!selectedMedia}
              onClick={handleConfirmSelect}
              className="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-bold transition shadow-xs"
            >
              Confirm Selection
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
