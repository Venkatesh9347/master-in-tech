import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { LiveClass, LiveClassStatus } from '../../types/liveClass';

interface LiveClassListProps {
  classes: LiveClass[];
  loading?: boolean;
  courseId?: number;
  isTutorView?: boolean;
  onRefresh?: () => void;
  onViewAttendance?: (liveClass: LiveClass) => void;
}

export default function LiveClassList({
  classes,
  loading = false,
  courseId,
  isTutorView = false,
  onViewAttendance,
}: LiveClassListProps) {
  const navigate = useNavigate();
  const [filter, setFilter] = useState<'all' | 'live' | 'upcoming' | 'completed'>('all');

  const filteredClasses = classes.filter((c) => {
    if (filter === 'live') return c.status === 'live';
    if (filter === 'upcoming') return c.status === 'scheduled';
    if (filter === 'completed') return c.status === 'completed';
    return true;
  });

  const getProviderBadge = (provider?: string) => {
    switch (provider) {
      case 'zoom':
        return { name: 'Zoom', bg: 'bg-blue-50 text-blue-700 border-blue-200', icon: '📹' };
      case 'teams':
        return { name: 'MS Teams', bg: 'bg-indigo-50 text-indigo-700 border-indigo-200', icon: '👥' };
      case 'google_meet':
        return { name: 'Google Meet', bg: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: '🟢' };
      case 'jitsi':
        return { name: 'Jitsi Meet', bg: 'bg-purple-50 text-purple-700 border-purple-200', icon: '🌐' };
      default:
        return { name: 'Live WebRTC', bg: 'bg-slate-50 text-slate-700 border-slate-200', icon: '🔗' };
    }
  };

  const getStatusBadge = (status: LiveClassStatus) => {
    switch (status) {
      case 'live':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black bg-red-600 text-white animate-pulse shadow-sm shadow-red-500/30">
            <span className="w-2 h-2 rounded-full bg-white animate-ping" />
            LIVE NOW
          </span>
        );
      case 'scheduled':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
            📅 Upcoming
          </span>
        );
      case 'completed':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200">
            ✓ Completed
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-50 text-slate-500">
            {status}
          </span>
        );
    }
  };

  const handleEnterClass = (liveClass: LiveClass) => {
    const cId = courseId || liveClass.course_id;
    if (isTutorView) {
      navigate(`/tutor/live/${liveClass.id}`);
    } else {
      navigate(`/student/courses/${cId}/live/${liveClass.id}`);
    }
  };

  if (loading) {
    return (
      <div className="space-y-4 py-6">
        {[1, 2].map((i) => (
          <div key={i} className="p-6 rounded-2xl bg-white border border-slate-200 animate-pulse flex flex-col gap-3">
            <div className="h-5 bg-slate-200 rounded w-1/3" />
            <div className="h-4 bg-slate-100 rounded w-2/3" />
            <div className="h-10 bg-slate-100 rounded mt-2" />
          </div>
        ))}
      </div>
    );
  }

  const liveCount = classes.filter((c) => c.status === 'live').length;
  const upcomingCount = classes.filter((c) => c.status === 'scheduled').length;

  return (
    <div className="space-y-6">
      {/* Live Now Featured Callout if any session is currently streaming */}
      {liveCount > 0 && filter === 'all' && (
        <div className="p-5 sm:p-6 rounded-2xl bg-gradient-to-r from-red-600 via-rose-600 to-red-700 text-white shadow-lg shadow-red-500/20 border border-red-400">
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <span className="w-2.5 h-2.5 rounded-full bg-white animate-ping" />
                <span className="text-xs font-black uppercase tracking-wider text-red-100">
                  Active Live Session in Progress
                </span>
              </div>
              <h3 className="text-lg sm:text-xl font-black text-white">
                {classes.find((c) => c.status === 'live')?.title}
              </h3>
              <p className="text-xs text-red-100">
                Interactive real-time coding, instructor screen share, and raise-hand Q&A are open.
              </p>
            </div>
            {classes.find((c) => c.status === 'live') && (
              <button
                type="button"
                onClick={() => handleEnterClass(classes.find((c) => c.status === 'live')!)}
                className="py-2.5 px-6 rounded-xl bg-white text-red-600 hover:bg-red-50 font-black text-sm shadow-md transition transform active:scale-95 shrink-0"
              >
                {isTutorView ? 'Host Live Session →' : 'Join Classroom Now →'}
              </button>
            )}
          </div>
        </div>
      )}

      {/* Filter Tabs */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
        <div className="flex items-center gap-1.5 p-1 bg-slate-100 rounded-xl text-xs font-bold text-slate-600">
          <button
            type="button"
            onClick={() => setFilter('all')}
            className={`px-3 py-1.5 rounded-lg transition ${
              filter === 'all' ? 'bg-white text-slate-900 shadow-xs' : 'hover:text-slate-900'
            }`}
          >
            All Classes ({classes.length})
          </button>
          <button
            type="button"
            onClick={() => setFilter('live')}
            className={`px-3 py-1.5 rounded-lg transition flex items-center gap-1.5 ${
              filter === 'live' ? 'bg-white text-red-600 shadow-xs' : 'hover:text-slate-900'
            }`}
          >
            {liveCount > 0 && <span className="w-2 h-2 rounded-full bg-red-600 animate-pulse" />}
            Live Now ({liveCount})
          </button>
          <button
            type="button"
            onClick={() => setFilter('upcoming')}
            className={`px-3 py-1.5 rounded-lg transition ${
              filter === 'upcoming' ? 'bg-white text-slate-900 shadow-xs' : 'hover:text-slate-900'
            }`}
          >
            Upcoming ({upcomingCount})
          </button>
          <button
            type="button"
            onClick={() => setFilter('completed')}
            className={`px-3 py-1.5 rounded-lg transition ${
              filter === 'completed' ? 'bg-white text-slate-900 shadow-xs' : 'hover:text-slate-900'
            }`}
          >
            Completed ({classes.filter((c) => c.status === 'completed').length})
          </button>
        </div>
      </div>

      {/* Classes Grid */}
      {filteredClasses.length === 0 ? (
        <div className="text-center py-12 px-4 rounded-2xl bg-slate-50 border border-dashed border-slate-200">
          <span className="text-3xl block mb-2">📹</span>
          <h4 className="text-sm font-bold text-slate-700">No live classes in this view</h4>
          <p className="text-xs text-slate-500 mt-1">
            {filter === 'live'
              ? 'There are currently no active live sessions. Check upcoming schedules.'
              : 'New live workshops and scheduled classes will appear here.'}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {filteredClasses.map((liveClass) => {
            const providerInfo = getProviderBadge(liveClass.provider);
            const isLive = liveClass.status === 'live';

            return (
              <div
                key={liveClass.id}
                className={`rounded-2xl p-5 sm:p-6 transition border flex flex-col justify-between ${
                  isLive
                    ? 'bg-white border-red-300 shadow-md shadow-red-500/5 ring-1 ring-red-400'
                    : 'bg-white border-slate-200 hover:border-slate-300 shadow-xs'
                }`}
              >
                <div>
                  {/* Top Bar: Status + Provider */}
                  <div className="flex items-center justify-between gap-2 mb-3">
                    <div className="flex items-center gap-2">
                      {getStatusBadge(liveClass.status)}
                      <span
                        className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${providerInfo.bg}`}
                      >
                        <span>{providerInfo.icon}</span>
                        <span>{providerInfo.name}</span>
                      </span>
                    </div>
                    <span className="text-xs font-semibold text-slate-400">
                      {liveClass.duration_minutes} mins
                    </span>
                  </div>

                  {/* Title & Description */}
                  <h4 className="text-base font-extrabold text-slate-900 line-clamp-1 mb-1.5">
                    {liveClass.title}
                  </h4>
                  {liveClass.description && (
                    <p className="text-xs text-slate-500 line-clamp-2 mb-4 leading-relaxed">
                      {liveClass.description}
                    </p>
                  )}

                  {/* Course Title if multi-course view */}
                  {liveClass.course && !courseId && (
                    <div className="mb-4 inline-block px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-xs font-semibold">
                      📚 {liveClass.course.title}
                    </div>
                  )}

                  {/* Date & Time Grid */}
                  <div className="p-3 rounded-xl bg-slate-50 border border-slate-100 space-y-1.5 mb-4 text-xs">
                    <div className="flex items-center justify-between text-slate-700">
                      <span className="text-slate-500 font-medium">📅 Date:</span>
                      <span className="font-bold">{liveClass.class_date}</span>
                    </div>
                    <div className="flex items-center justify-between text-slate-700">
                      <span className="text-slate-500 font-medium">⏰ Time:</span>
                      <span className="font-bold">
                        {liveClass.start_time} {liveClass.end_time ? `– ${liveClass.end_time}` : ''}
                      </span>
                    </div>
                    {liveClass.instructor && (
                      <div className="flex items-center justify-between text-slate-700 pt-1 border-t border-slate-200/60">
                        <span className="text-slate-500 font-medium">👨‍🏫 Instructor:</span>
                        <span className="font-bold">{liveClass.instructor.name}</span>
                      </div>
                    )}
                  </div>
                </div>

                {/* Bottom Action Button */}
                <div className="pt-2 space-y-2">
                  {liveClass.status === 'completed' ? (
                    <div className="flex gap-2">
                      <button
                        type="button"
                        onClick={() => handleEnterClass(liveClass)}
                        className="flex-1 py-2.5 px-3 rounded-xl text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition text-center"
                      >
                        View Session
                      </button>
                      {onViewAttendance && (
                        <button
                          type="button"
                          onClick={() => onViewAttendance(liveClass)}
                          className="py-2.5 px-3 rounded-xl text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 transition"
                        >
                          Attendance
                        </button>
                      )}
                    </div>
                  ) : isLive ? (
                    <div className="flex gap-2">
                      <button
                        type="button"
                        onClick={() => handleEnterClass(liveClass)}
                        className="flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-md shadow-red-500/20 transition text-center flex items-center justify-center gap-2"
                      >
                        <span className="w-2 h-2 rounded-full bg-white animate-ping" />
                        <span>{isTutorView ? 'Enter Host Classroom →' : 'Join Live Classroom →'}</span>
                      </button>
                      {isTutorView && onViewAttendance && (
                        <button
                          type="button"
                          onClick={() => onViewAttendance(liveClass)}
                          className="py-2.5 px-3 rounded-xl text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 transition"
                        >
                          Roster
                        </button>
                      )}
                    </div>
                  ) : (
                    <div className="flex gap-2">
                      <button
                        type="button"
                        onClick={() => handleEnterClass(liveClass)}
                        className="flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-md shadow-blue-500/10 transition text-center flex items-center justify-center gap-2"
                      >
                        <span>{isTutorView ? 'Open Host Console →' : 'Enter Classroom Area →'}</span>
                      </button>
                      {isTutorView && onViewAttendance && (
                        <button
                          type="button"
                          onClick={() => onViewAttendance(liveClass)}
                          className="py-2.5 px-3 rounded-xl text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 transition"
                        >
                          Roster
                        </button>
                      )}
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
