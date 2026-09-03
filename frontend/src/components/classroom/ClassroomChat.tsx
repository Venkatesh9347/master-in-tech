import { useState, useRef, useEffect } from 'react';
import type { ClassroomMessage } from '../../types/classroom';

interface ClassroomChatProps {
  messages: ClassroomMessage[];
  isChatEnabled: boolean;
  isHost: boolean;
  canSendChat: boolean;
  onSendMessage: (message: string) => Promise<void>;
  onToggleChatPermission?: (enabled: boolean) => void;
  sending?: boolean;
}

export default function ClassroomChat({
  messages,
  isChatEnabled,
  isHost,
  canSendChat,
  onSendMessage,
  onToggleChatPermission,
  sending = false,
}: ClassroomChatProps) {
  const [inputText, setInputText] = useState('');
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!inputText.trim() || sending) return;

    const text = inputText;
    setInputText('');
    await onSendMessage(text);
  };

  return (
    <div className="bg-slate-900 border border-slate-800 rounded-3xl p-5 space-y-4 shadow-xl flex flex-col h-full">
      {/* Top Header with Title and Host Chat Toggle */}
      <div className="flex items-center justify-between gap-2 border-b border-slate-800 pb-3">
        <h3 className="text-xs font-black text-white uppercase tracking-wider flex items-center gap-2">
          <span>💬</span>
          <span>Classroom Chat</span>
        </h3>

        {/* Host Toggle Switch */}
        {isHost && onToggleChatPermission && (
          <button
            type="button"
            onClick={() => onToggleChatPermission(!isChatEnabled)}
            className={`px-2.5 py-1 rounded-xl text-[10px] font-bold border transition flex items-center gap-1.5 ${
              isChatEnabled
                ? 'bg-emerald-950/80 border-emerald-600/60 text-emerald-300'
                : 'bg-red-950/80 border-red-600/60 text-red-300'
            }`}
          >
            <span>{isChatEnabled ? '✓ Chat Open' : '🔒 Chat Locked'}</span>
          </button>
        )}
      </div>

      {/* Messages Feed */}
      <div className="flex-grow space-y-3 overflow-y-auto max-h-[380px] pr-1">
        {messages.length === 0 ? (
          <div className="flex flex-col items-center justify-center p-8 text-center text-slate-500 text-xs">
            <span className="text-2xl mb-1">💭</span>
            <span>No messages yet.</span>
            <span className="text-[11px] text-slate-500 mt-0.5">
              Be the first to say hello to the class!
            </span>
          </div>
        ) : (
          messages.map((msg) => {
            const isHostMessage = msg.user?.role === 'admin' || msg.user?.role === 'tutor';

            return (
              <div
                key={msg.id}
                className={`p-3 rounded-2xl border text-xs space-y-1 ${
                  isHostMessage
                    ? 'bg-slate-950/90 border-blue-500/30'
                    : 'bg-slate-950/50 border-slate-800'
                }`}
              >
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-1.5 min-w-0">
                    <span className="font-bold text-white truncate text-[11px]">
                      {msg.user?.name || 'Participant'}
                    </span>
                    {isHostMessage && (
                      <span className="px-1.5 py-0.2 rounded bg-blue-600 text-white font-black text-[9px]">
                        {msg.user?.role === 'admin' ? 'HOST' : 'TUTOR'}
                      </span>
                    )}
                  </div>
                  <span className="text-[10px] text-slate-500 shrink-0">
                    {new Date(msg.created_at).toLocaleTimeString([], {
                      hour: '2-digit',
                      minute: '2-digit',
                    })}
                  </span>
                </div>
                <p className="text-slate-300 text-xs break-words leading-relaxed">{msg.message}</p>
              </div>
            );
          })
        )}
        <div ref={messagesEndRef} />
      </div>

      {/* Locked Notice for Students if Chat Disabled */}
      {!canSendChat && !isHost && (
        <div className="p-2.5 rounded-xl bg-amber-950/60 border border-amber-600/40 text-amber-300 text-xs flex items-center gap-2">
          <span>🔒</span>
          <span>Student chat is disabled by the instructor.</span>
        </div>
      )}

      {/* Message Input Form */}
      <form onSubmit={handleSubmit} className="flex items-center gap-2 pt-2 border-t border-slate-800">
        <input
          type="text"
          value={inputText}
          onChange={(e) => setInputText(e.target.value)}
          disabled={!canSendChat && !isHost}
          placeholder={
            !canSendChat && !isHost
              ? 'Chat is currently disabled...'
              : 'Type a message to the classroom...'
          }
          className="flex-grow px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white text-xs placeholder:text-slate-500 focus:outline-hidden focus:border-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
        />
        <button
          type="submit"
          disabled={(!canSendChat && !isHost) || !inputText.trim() || sending}
          className="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs transition shadow-xs disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1 shrink-0"
        >
          <span>Send</span>
          <span>→</span>
        </button>
      </form>
    </div>
  );
}
