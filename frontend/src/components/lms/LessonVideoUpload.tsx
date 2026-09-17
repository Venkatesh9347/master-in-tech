import { useCallback, useEffect, useRef, useState } from 'react';
import API from '../../services/api';

interface LessonVideoUploadProps {
  courseId: number;
  sectionId: number;
  lessonId: number;
  onStatusChange?: () => void;
}

interface VideoStatus {
  asset_id: string;
  status: 'processing' | 'ready' | 'error';
  error?: string | null;
  duration_seconds?: number;
  resolutions?: string[];
}

const ACCEPTED_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];
const ACCEPT_ATTR = 'video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov';
const MAX_BYTES = 512 * 1024 * 1024;

export default function LessonVideoUpload({
  courseId,
  sectionId,
  lessonId,
  onStatusChange,
}: LessonVideoUploadProps) {
  const [status, setStatus] = useState<VideoStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [error, setError] = useState('');
  const pollRef = useRef<number | null>(null);

  const baseUrl = `/courses/${courseId}/sections/${sectionId}/lessons/${lessonId}/video`;

  const fetchStatus = useCallback(async () => {
    try {
      const res = await API.get<VideoStatus>(baseUrl);
      setStatus(res.data);
      onStatusChange?.();
      return res.data.status;
    } catch (err: unknown) {
      const response = err as { response?: { status?: number } };
      if (response.response?.status === 404) {
        setStatus(null);
        return null;
      }
      throw err;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [baseUrl]);

  useEffect(() => {
    setLoading(true);
    setError('');
    fetchStatus()
      .catch(() => setError('Unable to load video status.'))
      .finally(() => setLoading(false));
  }, [fetchStatus]);

  useEffect(() => {
    return () => {
      if (pollRef.current !== null) {
        window.clearInterval(pollRef.current);
      }
    };
  }, []);

  const stopPolling = () => {
    if (pollRef.current !== null) {
      window.clearInterval(pollRef.current);
      pollRef.current = null;
    }
  };

  const startPolling = () => {
    stopPolling();
    pollRef.current = window.setInterval(async () => {
      try {
        const state = await fetchStatus();
        if (state === 'ready' || state === 'error') {
          stopPolling();
        }
      } catch {
        stopPolling();
        setError('Unable to load video status.');
      }
    }, 3000);
  };

  const handleFile = async (file: File | undefined) => {
    if (!file) return;
    setError('');

    if (!ACCEPTED_TYPES.includes(file.type) && !/\.(mp4|webm|mov)$/i.test(file.name)) {
      setError('Unsupported video format. Allowed formats: mp4, webm, mov.');
      return;
    }
    if (file.size > MAX_BYTES) {
      setError('Video file is too large. Maximum size is 512 MB.');
      return;
    }

    const formData = new FormData();
    formData.append('file', file);

    setUploading(true);
    setProgress(0);
    try {
      await API.post(baseUrl, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (event) => {
          if (event.total) {
            setProgress(Math.round((event.loaded / event.total) * 100));
          }
        },
      });
      await fetchStatus();
      startPolling();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Video upload failed.');
    } finally {
      setUploading(false);
    }
  };

  const handleRetry = async () => {
    setError('');
    try {
      await API.post(`${baseUrl}/retry`);
      await fetchStatus();
      startPolling();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Retry failed.');
    }
  };

  if (loading) {
    return <span className="text-[11px] text-slate-400">Checking video…</span>;
  }

  return (
    <div className="flex items-center gap-2">
      {status && (
        <span
          className={`text-[10px] font-bold px-1.5 py-0.5 rounded ${
            status.status === 'ready'
              ? 'bg-emerald-100 text-emerald-700'
              : status.status === 'error'
              ? 'bg-red-100 text-red-700'
              : 'bg-blue-100 text-blue-700'
          }`}
        >
          {status.status === 'ready'
            ? `✅ HLS ready${status.resolutions?.length ? ` (${status.resolutions.join(', ')})` : ''}`
            : status.status === 'error'
            ? '❌ Transcode failed'
            : '⏳ Transcoding…'}
        </span>
      )}
      {status?.status === 'error' && (
        <button
          type="button"
          onClick={handleRetry}
          className="px-2 py-1 rounded bg-slate-100 text-slate-700 hover:bg-slate-200 text-[11px] font-bold transition"
          title={status.error || 'Retry transcoding'}
        >
          Retry
        </button>
      )}
      <label className="px-2 py-1 rounded bg-slate-900 text-white hover:bg-slate-700 text-[11px] font-bold transition cursor-pointer">
        {uploading ? `Uploading ${progress}%` : status ? 'Replace video' : 'Upload video'}
        <input
          type="file"
          accept={ACCEPT_ATTR}
          disabled={uploading}
          className="hidden"
          onChange={(e) => {
            void handleFile(e.target.files?.[0]);
            e.target.value = '';
          }}
        />
      </label>
      {error && (
        <span className="text-[11px] text-red-600 max-w-[240px] truncate" title={error}>
          {error}
        </span>
      )}
      {status?.status === 'error' && status.error && (
        <span className="text-[11px] text-red-600 max-w-[240px] truncate" title={status.error}>
          {status.error}
        </span>
      )}
    </div>
  );
}
