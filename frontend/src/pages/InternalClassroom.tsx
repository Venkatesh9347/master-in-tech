import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import API from '../services/api';
import { useAuth } from '../context/useAuth';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import LiveClassRoom from '../components/livekit/LiveClassRoom';
import ParticipantPanel from '../components/classroom/ParticipantPanel';
import RaiseHandQueue from '../components/classroom/RaiseHandQueue';
import ClassroomChat from '../components/classroom/ClassroomChat';
import RaiseHandButton from '../components/classroom/RaiseHandButton';
import type {
  ClassroomParticipant,
  ClassroomPermissionRequest,
  ClassroomMessage,
  ClassroomStateResponse,
} from '../types/classroom';
import type {
  LiveClassroomSession,
  LiveClassroomTokenResponse,
} from '../types/liveClassroom';

export default function InternalClassroom() {
  const { sessionId } = useParams<{ sessionId: string }>();
  const { user } = useAuth();
  const navigate = useNavigate();

  const [session, setSession] = useState<LiveClassroomSession | null>(null);
  const [tokenData, setTokenData] = useState<LiveClassroomTokenResponse | null>(null);
  const [isHost, setIsHost] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notification, setNotification] = useState<string | null>(null);

  // Phase 2 Moderation & Realtime State
  const [participants, setParticipants] = useState<ClassroomParticipant[]>([]);
  const [pendingRequests, setPendingRequests] = useState<ClassroomPermissionRequest[]>([]);
  const [messages, setMessages] = useState<ClassroomMessage[]>([]);
  const [isChatEnabled, setIsChatEnabled] = useState(false);
  const [isHandRaised, setIsHandRaised] = useState(false);
  const [canPublish, setCanPublish] = useState(false);

  // Active Tab for Sidebar (Participants vs Chat)
  const [activeTab, setActiveTab] = useState<'participants' | 'chat'>('participants');
  const [actionLoadingUserId, setActionLoadingUserId] = useState<number | null>(null);
  const [resolveLoadingId, setResolveLoadingId] = useState<number | null>(null);
  const [sendingMessage, setSendingMessage] = useState(false);
  const [raiseHandLoading, setRaiseHandLoading] = useState(false);

  const pollingTimerRef = useRef<number | null>(null);

  const showNotification = (msg: string) => {
    setNotification(msg);
    setTimeout(() => setNotification(null), 4000);
  };

  // Sync classroom state from server
  const syncClassroomState = useCallback(async () => {
    if (!sessionId) return;
    try {
      const res = await API.get<ClassroomStateResponse>(`/classrooms/${sessionId}/state`);
      const data = res.data;

      setIsHost(data.is_host);
      setParticipants(data.participants);
      setPendingRequests(data.pending_requests);
      setMessages(data.messages);
      setIsChatEnabled(data.session.is_chat_enabled);

      if (data.my_participant) {
        if (data.my_participant.connection_state === 'removed') {
          alert('You have been removed from this classroom session by the instructor.');
          navigate(user?.role === 'student' ? '/student' : '/tutor');
          return;
        }
        setIsHandRaised(Boolean(data.my_participant.is_hand_raised));
        setCanPublish(Boolean(data.my_participant.is_mic_allowed || data.is_host));
      }
    } catch {
      // Background sync silent catch
    }
  }, [sessionId, navigate, user]);

  // Initial Fetch & LiveKit Token Request
  const fetchSessionAndToken = useCallback(async () => {
    if (!sessionId) return;
    setLoading(true);
    setError('');

    try {
      // 1. Fetch Token from ClassSession LiveKit Endpoint
      try {
        const tokenRes = await API.post<{
          token: string;
          ws_url: string;
          room_name: string;
          session: {
            id: number;
            title: string;
            description?: string;
            status: 'scheduled' | 'live' | 'completed' | 'cancelled';
            scheduled_date: string;
            start_time: string;
            end_time?: string;
            course?: { id: number; title: string; code?: string };
            tutor?: { id: number; name: string };
          };
          is_host: boolean;
          role: 'host' | 'participant';
          can_publish: boolean;
        }>(`/class-sessions/${sessionId}/livekit-token`);

        setTokenData({
          token: tokenRes.data.token,
          ws_url: tokenRes.data.ws_url,
          room_id: tokenRes.data.room_name,
          session: {
            id: tokenRes.data.session.id,
            room_id: tokenRes.data.room_name,
            title: tokenRes.data.session.title,
            status: tokenRes.data.session.status,
            scheduled_date: tokenRes.data.session.scheduled_date,
            start_time: tokenRes.data.session.start_time,
            end_time: tokenRes.data.session.end_time,
            course: tokenRes.data.session.course,
            tutor: tokenRes.data.session.tutor,
          },
          is_host: tokenRes.data.is_host,
          role: tokenRes.data.role,
          can_publish: tokenRes.data.can_publish,
          is_mic_allowed: tokenRes.data.can_publish,
          is_camera_allowed: tokenRes.data.can_publish,
        });

        setSession({
          id: tokenRes.data.session.id,
          room_id: tokenRes.data.room_name,
          batch_id: 0,
          title: tokenRes.data.session.title,
          description: tokenRes.data.session.description,
          scheduled_date: tokenRes.data.session.scheduled_date,
          start_time: tokenRes.data.session.start_time,
          end_time: tokenRes.data.session.end_time,
          status: tokenRes.data.session.status,
          course: tokenRes.data.session.course,
          tutor: tokenRes.data.session.tutor ? {
            id: tokenRes.data.session.tutor.id,
            name: tokenRes.data.session.tutor.name,
            email: '',
          } : null,
        });

        setIsHost(tokenRes.data.is_host);
        setCanPublish(tokenRes.data.can_publish);
      } catch (err: unknown) {
        const res = err as { response?: { status?: number; data?: { message?: string } } };
        if (res.response?.status !== 404) throw err;

        // Fallback to LiveClassroomSession
        const sessionRes = await API.get<{ session: LiveClassroomSession; is_host: boolean }>(
          `/live-classroom/sessions/${sessionId}`
        );
        setSession(sessionRes.data.session);
        setIsHost(sessionRes.data.is_host);

        const tokenRes2 = await API.post<LiveClassroomTokenResponse>(
          `/live-classroom/sessions/${sessionId}/token`
        );
        setTokenData(tokenRes2.data);
        setIsHost(tokenRes2.data.is_host);
        setCanPublish(tokenRes2.data.can_publish);
      }

      // Initial state sync
      await syncClassroomState();
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(
        response.response?.data?.message ||
          'Unable to join this Live Classroom session. Please verify your enrollment.'
      );
    } finally {
      setLoading(false);
    }
  }, [sessionId, syncClassroomState]);

  useEffect(() => {
    fetchSessionAndToken();
  }, [fetchSessionAndToken]);

  // Periodic polling for realtime moderation state (every 3s)
  useEffect(() => {
    if (!sessionId) return;
    const interval = window.setInterval(() => {
      syncClassroomState();
    }, 3000);
    pollingTimerRef.current = interval;

    return () => {
      if (pollingTimerRef.current) {
        clearInterval(pollingTimerRef.current);
      }
    };
  }, [sessionId, syncClassroomState]);

  // Student Action: Toggle Raise Hand
  const handleToggleRaiseHand = async () => {
    if (!sessionId || raiseHandLoading) return;
    setRaiseHandLoading(true);

    try {
      if (isHandRaised) {
        await API.post(`/classrooms/${sessionId}/lower-hand`);
        setIsHandRaised(false);
        showNotification('Hand lowered.');
      } else {
        await API.post(`/classrooms/${sessionId}/raise-hand`);
        setIsHandRaised(true);
        showNotification('Hand raised! The instructor has been notified.');
      }
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Error updating hand raise state.');
    } finally {
      setRaiseHandLoading(false);
    }
  };

  // Host Action: Resolve Raise Hand (Approve / Deny)
  const handleResolveRaiseHand = async (requestId: number, action: 'approve' | 'deny') => {
    if (!sessionId) return;
    setResolveLoadingId(requestId);

    try {
      const res = await API.post<{ message: string }>(
        `/classrooms/${sessionId}/requests/${requestId}/resolve`,
        { action }
      );
      showNotification(res.data.message);
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Failed to resolve speaking request.');
    } finally {
      setResolveLoadingId(null);
    }
  };

  // Host Action: Toggle Individual Student Mic
  const handleToggleMicModeration = async (targetUserId: number, currentState: boolean) => {
    if (!sessionId) return;
    setActionLoadingUserId(targetUserId);

    try {
      const res = await API.post<{ message: string }>(
        `/classrooms/${sessionId}/moderation/mic`,
        {
          target_user_id: targetUserId,
          is_mic_allowed: !currentState,
        }
      );
      showNotification(res.data.message);
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Failed to update microphone permission.');
    } finally {
      setActionLoadingUserId(null);
    }
  };

  // Host Action: Toggle Individual Student Camera
  const handleToggleCameraModeration = async (targetUserId: number, currentState: boolean) => {
    if (!sessionId) return;
    setActionLoadingUserId(targetUserId);

    try {
      const res = await API.post<{ message: string }>(
        `/classrooms/${sessionId}/moderation/camera`,
        {
          target_user_id: targetUserId,
          is_camera_allowed: !currentState,
        }
      );
      showNotification(res.data.message);
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Failed to update camera permission.');
    } finally {
      setActionLoadingUserId(null);
    }
  };

  // Host Action: Transfer Host Control to Assigned Tutor
  const handleTransferHost = async (targetUserId: number) => {
    if (!sessionId) return;
    if (!window.confirm('Are you sure you want to transfer active host control to this instructor?')) {
      return;
    }
    setActionLoadingUserId(targetUserId);

    try {
      const res = await API.post<{ message: string }>(
        `/classrooms/${sessionId}/transfer-host`,
        { target_user_id: targetUserId }
      );
      showNotification(res.data.message);
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Failed to transfer host privileges.');
    } finally {
      setActionLoadingUserId(null);
    }
  };

  // Host Action: Toggle Global Student Chat
  const handleToggleChatPermission = async (enabled: boolean) => {
    if (!sessionId) return;
    try {
      const res = await API.post<{ message: string }>(
        `/classrooms/${sessionId}/moderation/chat-toggle`,
        { is_chat_enabled: enabled }
      );
      setIsChatEnabled(enabled);
      showNotification(res.data.message);
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Failed to update chat permissions.');
    }
  };

  // Chat: Send Message
  const handleSendMessage = async (text: string) => {
    if (!sessionId || !text.trim()) return;
    setSendingMessage(true);

    try {
      await API.post(`/classrooms/${sessionId}/messages`, { message: text });
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Failed to send message.');
    } finally {
      setSendingMessage(false);
    }
  };

  // Host Action: Remove Participant
  const handleRemoveParticipant = async (targetUserId: number) => {
    if (!sessionId) return;
    if (!window.confirm('Are you sure you want to remove this participant from the classroom?')) {
      return;
    }
    setActionLoadingUserId(targetUserId);

    try {
      const res = await API.post<{ message: string }>(
        `/classrooms/${sessionId}/remove-participant`,
        { target_user_id: targetUserId }
      );
      showNotification(res.data.message);
      await syncClassroomState();
    } catch (err: unknown) {
      const res = err as { response?: { data?: { message?: string } } };
      showNotification(res.response?.data?.message || 'Failed to remove participant.');
    } finally {
      setActionLoadingUserId(null);
    }
  };

  // Leave Room
  const handleLeaveSession = async () => {
    if (sessionId) {
      try {
        await API.post(`/classrooms/${sessionId}/leave`);
      } catch {
        try {
          await API.post(`/class-sessions/${sessionId}/leave`);
        } catch {
          // Silent catch
        }
      }
    }
    if (user?.role === 'tutor') {
      navigate('/tutor');
    } else if (user?.role === 'admin') {
      navigate('/admin/class-sessions');
    } else {
      navigate('/student');
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-950 text-white flex flex-col">
        <Navbar />
        <div className="flex-grow flex items-center justify-center p-6">
          <div className="flex flex-col items-center gap-4 text-center max-w-sm">
            <div className="relative">
              <div className="w-14 h-14 rounded-2xl border-2 border-blue-500/20 border-t-blue-500 animate-spin" />
              <span className="absolute inset-0 flex items-center justify-center text-lg">⚡</span>
            </div>
            <div>
              <h3 className="text-sm font-bold text-white tracking-wide">
                Connecting to Live Classroom
              </h3>
              <p className="text-xs text-slate-400 mt-1">
                Authenticating session with LiveKit server...
              </p>
            </div>
          </div>
        </div>
        <Footer />
      </div>
    );
  }

  if (error && !session) {
    return (
      <div className="min-h-screen bg-slate-950 text-white flex flex-col">
        <Navbar />
        <div className="flex-grow flex items-center justify-center p-6">
          <div className="max-w-md w-full p-8 bg-slate-900/90 rounded-3xl border border-red-500/30 text-center shadow-2xl backdrop-blur-xl">
            <div className="w-16 h-16 rounded-2xl bg-red-950/60 border border-red-500/40 flex items-center justify-center mx-auto mb-4 text-3xl">
              🔒
            </div>
            <h2 className="text-lg font-black text-white mb-2">Access Denied</h2>
            <p className="text-xs text-slate-400 leading-relaxed mb-6">{error}</p>
            <button
              type="button"
              onClick={() => (user?.role === 'tutor' ? navigate('/tutor') : navigate('/student'))}
              className="py-2.5 px-6 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs shadow-lg transition"
            >
              ← Return to Dashboard
            </button>
          </div>
        </div>
        <Footer />
      </div>
    );
  }

  const isLive = session?.status === 'live';
  const isCompleted = session?.status === 'completed';
  const isAdmin = user?.role === 'admin' || user?.role === 'super_admin';

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col selection:bg-blue-600 selection:text-white">
      <Navbar />

      {/* Top Classroom Bar */}
      <header className="bg-slate-900/90 border-b border-slate-800 px-4 sm:px-6 py-3.5 backdrop-blur-md sticky top-0 z-40">
        <div className="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
          {/* Left: Breadcrumb & Title */}
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={handleLeaveSession}
              className="py-1.5 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition text-xs font-semibold flex items-center gap-1.5 border border-slate-700"
            >
              <span>←</span>
              <span>Leave Room</span>
            </button>

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
                  {session?.title}
                </h1>
              </div>

              <div className="flex flex-wrap items-center gap-2 text-[11px] text-slate-400 mt-0.5">
                {session?.course && <span>• Course: {session.course.title}</span>}
                {session?.tutor && <span>• Faculty: {session.tutor.name}</span>}
              </div>
            </div>
          </div>

          {/* Right: Raise Hand Button (for Student) & Role Badge */}
          <div className="flex items-center gap-2.5 text-xs">
            {!isHost && (
              <RaiseHandButton
                isHandRaised={isHandRaised}
                onToggle={handleToggleRaiseHand}
                loading={raiseHandLoading}
              />
            )}

            <div
              className={`px-3 py-1 rounded-xl text-xs font-extrabold flex items-center gap-1.5 border ${
                isHost
                  ? 'bg-amber-950/60 border-amber-600/60 text-amber-300'
                  : 'bg-blue-950/60 border-blue-600/60 text-blue-300'
              }`}
            >
              <span>{isHost ? '👑' : '🎓'}</span>
              <span>{isHost ? 'Host / Faculty' : 'Student Participant'}</span>
            </div>
          </div>
        </div>
      </header>

      {/* Main Classroom Layout */}
      <main className="flex-grow max-w-7xl w-full mx-auto p-4 sm:p-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Left 8 Columns: LiveKit Realtime Media Stage & Speaking Queue */}
        <div className="lg:col-span-8 flex flex-col gap-4">
          {/* Action Notification Toast */}
          {notification && (
            <div className="p-3 rounded-2xl bg-blue-950/90 border border-blue-500/50 text-blue-200 text-xs font-semibold flex items-center gap-2 animate-in fade-in shadow-xl">
              <span>⚡</span>
              <span>{notification}</span>
            </div>
          )}

          {/* Host Raise Hand Speaking Queue */}
          {isHost && pendingRequests.length > 0 && (
            <RaiseHandQueue
              requests={pendingRequests}
              onResolve={handleResolveRaiseHand}
              loadingId={resolveLoadingId}
            />
          )}

          {/* Realtime LiveKit Stage */}
          {tokenData ? (
            <div className="bg-slate-900 rounded-3xl border border-slate-800 p-4 shadow-2xl flex flex-col flex-grow min-h-[500px]">
              <LiveClassRoom
                token={tokenData.token}
                wsUrl={tokenData.ws_url}
                roomName={tokenData.room_id || tokenData.session.room_id || `masterintech-session-${sessionId}`}
                isHost={isHost}
                canPublish={canPublish}
                onLeave={handleLeaveSession}
              />
            </div>
          ) : (
            <div className="bg-slate-900 rounded-3xl border border-slate-800 p-8 flex items-center justify-center text-center min-h-[460px]">
              <p className="text-xs text-slate-400">Loading LiveKit WebRTC session...</p>
            </div>
          )}
        </div>

        {/* Right 4 Columns: Tabbed Sidebar (Participants vs Chat) */}
        <div className="lg:col-span-4 flex flex-col gap-3">
          {/* Tab Selector */}
          <div className="bg-slate-900/90 p-1.5 rounded-2xl border border-slate-800 grid grid-cols-2 gap-1 backdrop-blur-md">
            <button
              type="button"
              onClick={() => setActiveTab('participants')}
              className={`py-2 px-3 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 ${
                activeTab === 'participants'
                  ? 'bg-blue-600 text-white shadow-xs'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
            >
              <span>👥 Participants</span>
              <span className="px-1.5 py-0.2 rounded-md bg-slate-950/60 text-[10px]">
                {participants.length}
              </span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('chat')}
              className={`py-2 px-3 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 ${
                activeTab === 'chat'
                  ? 'bg-blue-600 text-white shadow-xs'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
            >
              <span>💬 Chat</span>
              <span
                className={`w-2 h-2 rounded-full ${
                  isChatEnabled ? 'bg-emerald-400' : 'bg-red-400'
                }`}
              />
            </button>
          </div>

          {/* Tab Content */}
          <div className="flex-grow flex flex-col min-h-[500px]">
            {activeTab === 'participants' ? (
              <ParticipantPanel
                participants={participants}
                currentUserId={user?.id}
                isHost={isHost}
                isAdmin={isAdmin}
                onToggleMic={handleToggleMicModeration}
                onToggleCamera={handleToggleCameraModeration}
                onTransferHost={handleTransferHost}
                onRemoveParticipant={handleRemoveParticipant}
                actionLoadingUserId={actionLoadingUserId}
              />
            ) : (
              <ClassroomChat
                messages={messages}
                isChatEnabled={isChatEnabled}
                isHost={isHost}
                canSendChat={isHost || isChatEnabled}
                onSendMessage={handleSendMessage}
                onToggleChatPermission={isHost ? handleToggleChatPermission : undefined}
                sending={sendingMessage}
              />
            )}
          </div>
        </div>
      </main>

      <Footer />
    </div>
  );
}
