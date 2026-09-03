import { useState, useEffect, useRef, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import API from '../services/api';
import { useAuth } from '../context/useAuth';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import type {
  LiveClass,
  LiveClassAttendance,
  LiveClassMessage,
  LiveClassState,
} from '../types/liveClass';

export default function LiveClassroom() {
  const { courseId, liveClassId } = useParams<{ courseId: string; liveClassId: string }>();
  const { user } = useAuth();
  const navigate = useNavigate();

  const [liveClass, setLiveClass] = useState<LiveClass | null>(null);
  const [isHost, setIsHost] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  // Attendance & Student State
  const [myAttendance, setMyAttendance] = useState<LiveClassAttendance | null>(null);
  const [isJoined, setIsJoined] = useState(false);
  const [raisingHand, setRaisingHand] = useState(false);

  // Real-time Messages & Polling State
  const [messages, setMessages] = useState<LiveClassMessage[]>([]);
  const [newMessage, setNewMessage] = useState('');
  const [sendingMessage, setSendingMessage] = useState(false);
  const [isChatEnabled, setIsChatEnabled] = useState(true);

  // Live State (Active Participants & Raised Hands)
  const [activeCount, setActiveCount] = useState(0);
  const [raisedHands, setRaisedHands] = useState<LiveClassAttendance[]>([]);
  const [participants, setParticipants] = useState<LiveClassAttendance[]>([]);

  // Side Panel Tabs ('chat' | 'participants' | 'host')
  const [activeTab, setActiveTab] = useState<'chat' | 'participants' | 'host'>('chat');
  const [actionLoading, setActionLoading] = useState(false);
  const [actionSuccess, setActionSuccess] = useState('');

  const chatScrollRef = useRef<HTMLDivElement>(null);

  // Fetch initial details
  const fetchClassDetails = useCallback(async () => {
    if (!liveClassId) return;
    try {
      const res = await API.get<{
        live_class: LiveClass;
        launch: {
          meeting_url?: string;
          is_host: boolean;
          is_chat_enabled: boolean;
        };
        my_attendance?: LiveClassAttendance;
        is_host: boolean;
        active_participants_count: number;
        raised_hands_count: number;
      }>(`/live-classes/${liveClassId}`);

      setLiveClass(res.data.live_class);
      setIsHost(res.data.is_host);
      setIsChatEnabled(res.data.live_class.is_chat_enabled);
      setMyAttendance(res.data.my_attendance || null);
      setIsJoined(Boolean(res.data.my_attendance && !res.data.my_attendance.left_at));
      setActiveCount(res.data.active_participants_count || 0);
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Unable to access this live classroom.');
    } finally {
      setLoading(false);
    }
  }, [liveClassId]);

  // Fetch real-time chat messages
  const fetchMessages = useCallback(async () => {
    if (!liveClassId) return;
    try {
      const res = await API.get<LiveClassMessage[]>(`/live-classes/${liveClassId}/messages`);
      setMessages(Array.isArray(res.data) ? res.data : []);
    } catch {
      // Ignore polling errors
    }
  }, [liveClassId]);

  // Fetch classroom live state (participants, hand raises, chat toggle)
  const fetchState = useCallback(async () => {
    if (!liveClassId) return;
    try {
      const res = await API.get<LiveClassState>(`/live-classes/${liveClassId}/state`);
      setIsChatEnabled(res.data.is_chat_enabled);
      setActiveCount(res.data.active_count);
      setRaisedHands(res.data.raised_hands || []);
      if (res.data.my_attendance) {
        setMyAttendance(res.data.my_attendance);
      }
      if (res.data.participants) {
        setParticipants(res.data.participants);
      }
      if (res.data.status && liveClass && liveClass.status !== res.data.status) {
        setLiveClass((prev) => (prev ? { ...prev, status: res.data.status } : null));
      }
    } catch {
      // Ignore polling errors
    }
  }, [liveClassId, liveClass]);

  useEffect(() => {
    fetchClassDetails();
    fetchMessages();
  }, [fetchClassDetails, fetchMessages]);

  // Auto-scroll chat to bottom on new message
  useEffect(() => {
    chatScrollRef.current?.scrollTo({
      top: chatScrollRef.current.scrollHeight,
      behavior: 'smooth',
    });
  }, [messages]);

  // 3-second live state polling interval
  useEffect(() => {
    if (!liveClassId) return;
    const interval = setInterval(() => {
      fetchState();
      fetchMessages();
    }, 3000);

    return () => clearInterval(interval);
  }, [liveClassId, fetchState, fetchMessages]);

  // Join Classroom handler
  const handleJoinClass = async () => {
    if (!liveClassId) return;
    setActionLoading(true);
    setError('');
    try {
      const res = await API.post<{ attendance: LiveClassAttendance }>(`/live-classes/${liveClassId}/join`);
      setMyAttendance(res.data.attendance);
      setIsJoined(true);
      fetchState();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Failed to join live classroom.');
    } finally {
      setActionLoading(false);
    }
  };

  // Leave Classroom handler
  const handleLeaveClass = async () => {
    if (!liveClassId) return;
    setActionLoading(true);
    try {
      await API.post(`/live-classes/${liveClassId}/leave`);
      setIsJoined(false);
      setMyAttendance((prev) => (prev ? { ...prev, left_at: new Date().toISOString(), status: 'left' } : null));
      fetchState();
    } catch {
      // Ignore
    } finally {
      setActionLoading(false);
    }
  };

  // Student Raise/Lower Hand
  const handleToggleRaiseHand = async () => {
    if (!liveClassId || raisingHand) return;
    setRaisingHand(true);
    try {
      if (myAttendance?.is_hand_raised) {
        const res = await API.post<{ attendance: LiveClassAttendance }>(`/live-classes/${liveClassId}/lower-hand`);
        setMyAttendance(res.data.attendance);
      } else {
        const res = await API.post<{ attendance: LiveClassAttendance }>(`/live-classes/${liveClassId}/raise-hand`);
        setMyAttendance(res.data.attendance);
      }
      fetchState();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Unable to update hand status.');
    } finally {
      setRaisingHand(false);
    }
  };

  // Send Chat Message
  const handleSendMessage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!liveClassId || !newMessage.trim() || sendingMessage) return;

    setSendingMessage(true);
    try {
      const res = await API.post<LiveClassMessage>(`/live-classes/${liveClassId}/messages`, {
        message: newMessage.trim(),
        is_announcement: isHost && activeTab === 'host',
      });
      setMessages((prev) => [...prev, res.data]);
      setNewMessage('');
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Unable to send message.');
    } finally {
      setSendingMessage(false);
    }
  };

  // Tutor: Grant Mic Permission
  const handleAllowMic = async (participantUserId: number) => {
    if (!liveClassId) return;
    try {
      await API.post(`/tutor/live-classes/${liveClassId}/participants/${participantUserId}/allow-mic`);
      setActionSuccess('Microphone permission granted.');
      setTimeout(() => setActionSuccess(''), 3000);
      fetchState();
    } catch {
      setError('Failed to grant microphone permission.');
    }
  };

  // Tutor: Revoke Mic Permission
  const handleRevokeMic = async (participantUserId: number) => {
    if (!liveClassId) return;
    try {
      await API.post(`/tutor/live-classes/${liveClassId}/participants/${participantUserId}/revoke-mic`);
      setActionSuccess('Microphone permission revoked.');
      setTimeout(() => setActionSuccess(''), 3000);
      fetchState();
    } catch {
      setError('Failed to revoke microphone permission.');
    }
  };

  // Tutor: Lower Student Hand
  const handleTutorLowerHand = async (participantUserId: number) => {
    if (!liveClassId) return;
    try {
      await API.post(`/tutor/live-classes/${liveClassId}/participants/${participantUserId}/lower-hand`);
      fetchState();
    } catch {
      // Ignore
    }
  };

  // Tutor: Toggle Student Chat
  const handleToggleChat = async () => {
    if (!liveClassId) return;
    try {
      const res = await API.post<{ is_chat_enabled: boolean }>(`/tutor/live-classes/${liveClassId}/toggle-chat`);
      setIsChatEnabled(res.data.is_chat_enabled);
      setActionSuccess(res.data.is_chat_enabled ? 'Student chat enabled.' : 'Student chat disabled.');
      setTimeout(() => setActionSuccess(''), 3000);
    } catch {
      setError('Failed to toggle chat state.');
    }
  };

  // Tutor: Start Class
  const handleStartClass = async () => {
    if (!liveClassId) return;
    try {
      const res = await API.post<{ live_class: LiveClass }>(`/tutor/live-classes/${liveClassId}/start`);
      setLiveClass(res.data.live_class);
      setActionSuccess('Live classroom is now LIVE.');
      setTimeout(() => setActionSuccess(''), 3000);
      fetchState();
    } catch {
      setError('Failed to start session.');
    }
  };

  // Tutor: End Class
  const handleEndClass = async () => {
    if (!liveClassId || !window.confirm('Are you sure you want to end this live classroom session?')) return;
    try {
      const res = await API.post<{ live_class: LiveClass }>(`/tutor/live-classes/${liveClassId}/end`);
      setLiveClass(res.data.live_class);
      setActionSuccess('Live classroom session completed.');
      setTimeout(() => setActionSuccess(''), 3000);
      fetchState();
    } catch {
      setError('Failed to end session.');
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-950 text-white flex flex-col">
        <Navbar />
        <div className="flex-grow flex items-center justify-center">
          <div className="flex items-center gap-3 text-slate-400 font-semibold">
            <span className="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
            <span>Connecting to MasterInTech Live Classroom...</span>
          </div>
        </div>
        <Footer />
      </div>
    );
  }

  if (error && !liveClass) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col">
        <Navbar />
        <div className="flex-grow flex items-center justify-center p-6">
          <div className="max-w-md w-full p-8 bg-white rounded-3xl border border-red-200 text-center shadow-xl">
            <span className="text-4xl block mb-3">🔒</span>
            <h2 className="text-lg font-black text-slate-900 mb-2">Access Restricted</h2>
            <p className="text-xs text-slate-600 mb-6">{error}</p>
            <button
              type="button"
              onClick={() => navigate(courseId ? `/student/courses/${courseId}` : '/courses')}
              className="py-2.5 px-6 rounded-xl bg-blue-600 text-white font-bold text-xs"
            >
              ← Back to Course
            </button>
          </div>
        </div>
        <Footer />
      </div>
    );
  }

  const isLive = liveClass?.status === 'live';
  const isCompleted = liveClass?.status === 'completed';
  const isMicAllowed = myAttendance?.is_mic_allowed || false;

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col selection:bg-blue-600 selection:text-white">
      <Navbar />

      {/* Classroom Top Banner */}
      <div className="bg-slate-900/90 border-b border-slate-800 px-4 sm:px-6 py-3.5 backdrop-blur-md sticky top-0 z-40">
        <div className="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
          {/* Breadcrumb & Title */}
          <div className="flex items-center gap-3">
            <Link
              to={courseId ? `/student/courses/${courseId}/lessons` : '/student'}
              className="p-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition text-xs flex items-center gap-1.5"
            >
              <span>←</span>
              <span className="hidden sm:inline">Exit to LMS</span>
            </Link>

            <div>
              <div className="flex items-center gap-2">
                {isLive ? (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-black bg-red-600 text-white animate-pulse">
                    <span className="w-1.5 h-1.5 rounded-full bg-white animate-ping" />
                    LIVE
                  </span>
                ) : isCompleted ? (
                  <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-800 text-slate-400 border border-slate-700">
                    Completed
                  </span>
                ) : (
                  <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-900/60 text-blue-300 border border-blue-700">
                    Scheduled
                  </span>
                )}
                <h1 className="text-sm sm:text-base font-extrabold text-white tracking-tight line-clamp-1">
                  {liveClass?.title}
                </h1>
              </div>
              {liveClass?.course && (
                <p className="text-[11px] text-slate-400 line-clamp-1 mt-0.5">
                  📚 {liveClass.course.title} • Instructor: {liveClass.instructor?.name || 'Assigned Tutor'}
                </p>
              )}
            </div>
          </div>

          {/* Top Right Status Stats */}
          <div className="flex items-center gap-3 text-xs">
            <div className="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-800/80 border border-slate-700">
              <span className="text-emerald-400 font-black">●</span>
              <span className="text-slate-300 font-semibold">{activeCount} Participants</span>
            </div>

            {raisedHands.length > 0 && isHost && (
              <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-950/60 border border-amber-600/50 text-amber-300 font-bold animate-pulse">
                <span>✋</span>
                <span>{raisedHands.length} Hand(s) Raised</span>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Main Classroom Grid */}
      <main className="flex-grow max-w-7xl w-full mx-auto p-4 sm:p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left 2 Columns: Video Stage & Controls */}
        <div className="lg:col-span-2 flex flex-col gap-4">
          {/* Action Success / Error Notifications */}
          {actionSuccess && (
            <div className="p-3 rounded-xl bg-emerald-950/80 border border-emerald-500 text-emerald-300 text-xs font-semibold flex items-center gap-2 animate-in fade-in">
              <span>✓</span>
              <span>{actionSuccess}</span>
            </div>
          )}
          {error && (
            <div className="p-3 rounded-xl bg-red-950/80 border border-red-500 text-red-300 text-xs font-semibold flex items-center gap-2">
              <span>⚠️</span>
              <span>{error}</span>
            </div>
          )}

          {/* Interactive Live Video Stage */}
          <div className="bg-slate-900 rounded-3xl border border-slate-800 overflow-hidden shadow-2xl flex flex-col justify-between min-h-[420px] sm:min-h-[500px] relative">
            {liveClass?.provider === 'jitsi' && liveClass.meeting_id && isJoined ? (
              /* Embedded Jitsi Meeting Stream */
              <div className="w-full h-full flex-grow relative bg-black">
                <iframe
                  title="Jitsi Classroom"
                  src={`https://meet.jit.si/${liveClass.meeting_id}#userInfo.displayName="${encodeURIComponent(
                    user?.name || 'Student'
                  )}"`}
                  allow="camera; microphone; fullscreen; display-capture; autoplay"
                  className="w-full h-[520px] border-0"
                />
              </div>
            ) : (
              /* Universal Interactive Video Stage Banner */
              <div className="flex-grow flex flex-col items-center justify-center p-8 sm:p-12 text-center relative overflow-hidden">
                <div className="absolute inset-0 bg-gradient-to-b from-blue-950/20 via-slate-900 to-slate-950 pointer-events-none" />

                <div className="relative z-10 max-w-md space-y-4">
                  <div className="w-20 h-20 rounded-3xl bg-slate-800 border border-slate-700 flex items-center justify-center mx-auto text-4xl shadow-inner">
                    {liveClass?.provider === 'zoom'
                      ? '📹'
                      : liveClass?.provider === 'teams'
                      ? '👥'
                      : liveClass?.provider === 'google_meet'
                      ? '🟢'
                      : '🎓'}
                  </div>

                  <div>
                    <h2 className="text-xl sm:text-2xl font-black text-white">{liveClass?.title}</h2>
                    <p className="text-xs text-slate-400 mt-1">
                      {liveClass?.description || 'Interactive live masterclass session'}
                    </p>
                  </div>

                  {/* Provider & Meeting Details Box */}
                  <div className="p-4 rounded-2xl bg-slate-950/80 border border-slate-800 space-y-2 text-xs text-left">
                    <div className="flex items-center justify-between text-slate-400">
                      <span>Platform Provider:</span>
                      <span className="font-bold text-white uppercase tracking-wider">
                        {liveClass?.provider}
                      </span>
                    </div>
                    {liveClass?.meeting_id && (
                      <div className="flex items-center justify-between text-slate-400">
                        <span>Meeting ID:</span>
                        <span className="font-mono text-blue-400 font-bold">{liveClass.meeting_id}</span>
                      </div>
                    )}
                    {liveClass?.passcode && (
                      <div className="flex items-center justify-between text-slate-400">
                        <span>Passcode:</span>
                        <span className="font-mono text-emerald-400 font-bold">{liveClass.passcode}</span>
                      </div>
                    )}
                  </div>

                  {/* Launch External Platform Button if URL present */}
                  {liveClass?.meeting_url && (
                    <div className="pt-2">
                      <a
                        href={isHost && liveClass.host_url ? liveClass.host_url : liveClass.meeting_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        onClick={() => {
                          if (!isJoined) handleJoinClass();
                        }}
                        className="w-full py-3 px-6 rounded-xl font-black text-sm text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/20 transition flex items-center justify-center gap-2"
                      >
                        <span>Launch {liveClass.provider.toUpperCase()} Video Stream ↗</span>
                      </a>
                      <p className="text-[11px] text-slate-500 mt-2">
                        Clicking launch will open the official stream while recording your attendance here.
                      </p>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Bottom Control Bar */}
            <div className="bg-slate-950 p-4 border-t border-slate-800 flex flex-wrap items-center justify-between gap-4">
              {/* Left: Join / Leave & Mic Status */}
              <div className="flex items-center gap-3">
                {!isJoined ? (
                  <button
                    type="button"
                    onClick={handleJoinClass}
                    disabled={actionLoading}
                    className="py-2.5 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md transition flex items-center gap-2"
                  >
                    <span>🚪 Join Classroom Session</span>
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={handleLeaveClass}
                    disabled={actionLoading}
                    className="py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition"
                  >
                    Leave Session
                  </button>
                )}

                {/* Microphone Permission Indicator Badge */}
                <div
                  className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold border ${
                    isMicAllowed
                      ? 'bg-emerald-950/60 text-emerald-300 border-emerald-700 animate-pulse'
                      : 'bg-slate-900 text-slate-400 border-slate-800'
                  }`}
                >
                  <span>{isMicAllowed ? '🎙️' : '🔒'}</span>
                  <span>
                    {isMicAllowed ? 'Microphone Allowed' : 'Mic Muted (Host Permission Required)'}
                  </span>
                </div>
              </div>

              {/* Right: Raise Hand Toggle */}
              {isJoined && !isHost && (
                <div>
                  <button
                    type="button"
                    onClick={handleToggleRaiseHand}
                    disabled={raisingHand}
                    className={`py-2.5 px-5 rounded-xl font-bold text-xs transition flex items-center gap-2 shadow-md ${
                      myAttendance?.is_hand_raised
                        ? 'bg-amber-500 hover:bg-amber-600 text-slate-950 animate-bounce'
                        : 'bg-slate-800 hover:bg-slate-700 text-white border border-slate-700'
                    }`}
                  >
                    <span>✋</span>
                    <span>{myAttendance?.is_hand_raised ? 'Hand Raised (Click to Lower)' : 'Raise Hand'}</span>
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Right Column: Chat, Participants, & Host Console */}
        <div className="bg-slate-900 rounded-3xl border border-slate-800 flex flex-col h-[580px] overflow-hidden shadow-xl">
          {/* Tab Navigation */}
          <div className="flex border-b border-slate-800 p-2 bg-slate-950/60">
            <button
              type="button"
              onClick={() => setActiveTab('chat')}
              className={`flex-1 py-2 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 ${
                activeTab === 'chat' ? 'bg-slate-800 text-white shadow-xs' : 'text-slate-400 hover:text-white'
              }`}
            >
              <span>💬</span>
              <span>Live Chat</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('participants')}
              className={`flex-1 py-2 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 ${
                activeTab === 'participants'
                  ? 'bg-slate-800 text-white shadow-xs'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <span>👥</span>
              <span>People ({activeCount})</span>
            </button>
            {isHost && (
              <button
                type="button"
                onClick={() => setActiveTab('host')}
                className={`flex-1 py-2 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 ${
                  activeTab === 'host'
                    ? 'bg-blue-600 text-white shadow-xs'
                    : 'text-blue-400 hover:text-blue-300'
                }`}
              >
                <span>⚙️</span>
                <span>Host Controls</span>
              </button>
            )}
          </div>

          {/* Tab Content 1: Live Chat */}
          {activeTab === 'chat' && (
            <div className="flex flex-col flex-grow overflow-hidden">
              {/* Message List */}
              <div ref={chatScrollRef} className="flex-grow p-4 overflow-y-auto space-y-3">
                {messages.length === 0 ? (
                  <div className="text-center py-16 text-slate-500 text-xs">
                    <span>👋 No messages yet. Say hello in the classroom!</span>
                  </div>
                ) : (
                  messages.map((msg) => (
                    <div
                      key={msg.id}
                      className={`p-3 rounded-2xl text-xs ${
                        msg.is_announcement
                          ? 'bg-blue-950/60 border border-blue-600/40 text-blue-200'
                          : 'bg-slate-800/70 border border-slate-700/50 text-slate-200'
                      }`}
                    >
                      <div className="flex items-center justify-between gap-2 mb-1">
                        <div className="flex items-center gap-1.5 font-bold">
                          <span className="text-white">{msg.user?.name || 'User'}</span>
                          {msg.user?.role === 'tutor' || msg.user?.role === 'admin' ? (
                            <span className="px-1.5 py-0.2 rounded bg-blue-600 text-[10px] text-white font-extrabold">
                              HOST
                            </span>
                          ) : null}
                        </div>
                        <span className="text-[10px] text-slate-500">
                          {new Date(msg.created_at).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                          })}
                        </span>
                      </div>
                      <p className="leading-relaxed whitespace-pre-wrap">{msg.message}</p>
                    </div>
                  ))
                )}
              </div>

              {/* Message Input / Host Chat Locked Notice */}
              <div className="p-3 bg-slate-950 border-t border-slate-800">
                {!isChatEnabled && !isHost ? (
                  <div className="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-center text-xs text-slate-400 font-medium">
                    🔒 Classroom chat has been disabled by the instructor.
                  </div>
                ) : (
                  <form onSubmit={handleSendMessage} className="flex items-center gap-2">
                    <input
                      type="text"
                      value={newMessage}
                      onChange={(e) => setNewMessage(e.target.value)}
                      placeholder={isChatEnabled ? 'Send a message to classroom...' : 'Chat disabled for students'}
                      className="flex-grow px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-800 text-xs text-white placeholder:text-slate-500 focus:outline-none focus:border-blue-500"
                    />
                    <button
                      type="submit"
                      disabled={sendingMessage || !newMessage.trim()}
                      className="p-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs transition disabled:opacity-40 shrink-0"
                    >
                      Send
                    </button>
                  </form>
                )}
              </div>
            </div>
          )}

          {/* Tab Content 2: Participants & Hand Raise Queue */}
          {activeTab === 'participants' && (
            <div className="flex-grow p-4 overflow-y-auto space-y-4">
              {/* Raised Hands Priority Queue */}
              {raisedHands.length > 0 && (
                <div className="space-y-2">
                  <h4 className="text-[11px] font-extrabold uppercase tracking-wider text-amber-400 flex items-center gap-1.5">
                    <span>✋</span>
                    <span>Raised Hand Queue ({raisedHands.length})</span>
                  </h4>
                  <div className="space-y-1.5">
                    {raisedHands.map((p) => (
                      <div
                        key={p.id}
                        className="p-2.5 rounded-xl bg-amber-950/40 border border-amber-600/40 flex items-center justify-between gap-2"
                      >
                        <div className="text-xs">
                          <span className="font-bold text-white block">{p.user?.name || 'Student'}</span>
                          <span className="text-[10px] text-amber-300">Wants to speak</span>
                        </div>
                        {isHost && (
                          <div className="flex items-center gap-1">
                            <button
                              type="button"
                              onClick={() => handleAllowMic(p.user_id)}
                              className="px-2 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-bold"
                            >
                              Allow Mic
                            </button>
                            <button
                              type="button"
                              onClick={() => handleTutorLowerHand(p.user_id)}
                              className="px-2 py-1 rounded-lg bg-slate-800 text-slate-300 text-[11px]"
                            >
                              Lower
                            </button>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Active Participants List */}
              <div className="space-y-2">
                <h4 className="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                  Active in Classroom ({activeCount})
                </h4>
                {participants.length === 0 ? (
                  <p className="text-xs text-slate-500">Participants will appear as they join.</p>
                ) : (
                  <div className="space-y-1.5">
                    {participants.map((p) => (
                      <div
                        key={p.id}
                        className="p-2.5 rounded-xl bg-slate-800/50 border border-slate-800 flex items-center justify-between text-xs"
                      >
                        <div className="flex items-center gap-2">
                          <div className="w-7 h-7 rounded-lg bg-blue-600 flex items-center justify-center font-bold text-white text-xs">
                            {p.user?.name?.charAt(0) || 'S'}
                          </div>
                          <div>
                            <span className="font-bold text-white block">{p.user?.name}</span>
                            <span className="text-[10px] text-slate-400">
                              {p.is_mic_allowed ? '🎙️ Mic Active' : '🔒 Muted'}
                            </span>
                          </div>
                        </div>

                        {isHost && p.user_id !== user?.id && (
                          <div className="flex items-center gap-1">
                            {p.is_mic_allowed ? (
                              <button
                                type="button"
                                onClick={() => handleRevokeMic(p.user_id)}
                                className="px-2 py-1 rounded bg-slate-700 hover:bg-slate-600 text-[10px] text-amber-300 font-bold"
                              >
                                Mute
                              </button>
                            ) : (
                              <button
                                type="button"
                                onClick={() => handleAllowMic(p.user_id)}
                                className="px-2 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-[10px] text-white font-bold"
                              >
                                Allow Mic
                              </button>
                            )}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Tab Content 3: Host Control Panel (Tutor / Admin Only) */}
          {activeTab === 'host' && isHost && (
            <div className="flex-grow p-4 overflow-y-auto space-y-4 text-xs">
              {/* Session State Controls */}
              <div className="p-3.5 rounded-2xl bg-slate-950 border border-slate-800 space-y-3">
                <h4 className="font-extrabold text-white text-xs uppercase tracking-wider">
                  Live Session Status
                </h4>
                <div className="flex gap-2">
                  {!isLive ? (
                    <button
                      type="button"
                      onClick={handleStartClass}
                      className="flex-1 py-2.5 px-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-xs transition text-center shadow-md shadow-red-500/20"
                    >
                      🔴 Start Live Class
                    </button>
                  ) : (
                    <button
                      type="button"
                      onClick={handleEndClass}
                      className="flex-1 py-2.5 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-red-400 font-bold text-xs transition text-center"
                    >
                      ⏹️ End Live Class
                    </button>
                  )}
                </div>
              </div>

              {/* Classroom Permissions Toggle */}
              <div className="p-3.5 rounded-2xl bg-slate-950 border border-slate-800 space-y-3">
                <h4 className="font-extrabold text-white text-xs uppercase tracking-wider">
                  Classroom Permissions
                </h4>

                <div className="flex items-center justify-between">
                  <div>
                    <span className="font-bold text-white block">Student Chat</span>
                    <span className="text-[11px] text-slate-400">
                      {isChatEnabled ? 'Students can send messages' : 'Only host can post'}
                    </span>
                  </div>
                  <button
                    type="button"
                    onClick={handleToggleChat}
                    className={`px-3 py-1.5 rounded-xl font-bold text-xs transition ${
                      isChatEnabled
                        ? 'bg-emerald-600 text-white'
                        : 'bg-slate-800 text-slate-400 border border-slate-700'
                    }`}
                  >
                    {isChatEnabled ? 'Enabled' : 'Disabled'}
                  </button>
                </div>
              </div>

              {/* Quick Navigation to Full Attendance Sheet */}
              <div className="p-3.5 rounded-2xl bg-slate-950 border border-slate-800 space-y-2">
                <h4 className="font-extrabold text-white text-xs uppercase tracking-wider">
                  Session Attendance Log
                </h4>
                <p className="text-slate-400 text-[11px]">
                  All participant join times, leave times, and attendance duration are automatically recorded.
                </p>
                <Link
                  to={courseId ? `/tutor/courses/${courseId}/live` : '/tutor'}
                  className="block py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-center font-bold text-blue-400 text-xs transition"
                >
                  View Full Course Live Sessions →
                </Link>
              </div>
            </div>
          )}
        </div>
      </main>

      <Footer />
    </div>
  );
}
