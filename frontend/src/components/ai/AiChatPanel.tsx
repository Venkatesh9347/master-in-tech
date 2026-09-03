import { useEffect, useRef, useState } from 'react';
import API from '../../services/api';
import type { AiConversation, AiMessage } from '../../types/ai';

interface AiChatPanelProps {
  conversationId?: number | null;
  onConversationCreated?: (conversation: AiConversation) => void;
  onMessagesUpdated?: (conversationId: number, messages: AiMessage[]) => void;
  className?: string;
}

export default function AiChatPanel({
  conversationId,
  onConversationCreated,
  onMessagesUpdated,
  className = '',
}: AiChatPanelProps) {
  const [messages, setMessages] = useState<AiMessage[]>([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [loadingConversation, setLoadingConversation] = useState(false);
  const [error, setError] = useState('');
  const [activeConversationId, setActiveConversationId] = useState<number | null>(conversationId ?? null);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setActiveConversationId(conversationId ?? null);
  }, [conversationId]);

  useEffect(() => {
    if (!activeConversationId) {
      setMessages([]);
      return;
    }

    setLoadingConversation(true);
    setError('');

    API.get<AiConversation>(`/ai/conversations/${activeConversationId}`)
      .then((res) => {
        const loaded = res.data.messages ?? [];
        setMessages(loaded);
        onMessagesUpdated?.(activeConversationId, loaded);
      })
      .catch((err: unknown) => {
        const response = err as { response?: { data?: { message?: string } } };
        setError(response.response?.data?.message || 'Unable to load this conversation.');
        setMessages([]);
      })
      .finally(() => setLoadingConversation(false));
  }, [activeConversationId, onMessagesUpdated]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, loading]);

  const handleSend = async () => {
    const trimmed = input.trim();
    if (!trimmed || loading) return;

    setLoading(true);
    setError('');

    const optimisticUserMessage: AiMessage = {
      id: Date.now(),
      role: 'user',
      content: trimmed,
    };

    setMessages((prev) => [...prev, optimisticUserMessage]);
    setInput('');

    try {
      const payload: { message: string; conversation_id?: number } = { message: trimmed };
      if (activeConversationId) {
        payload.conversation_id = activeConversationId;
      }

      const res = await API.post<{
        conversation_id: number;
        reply: string;
        conversation: AiConversation;
      }>('/ai/chat', payload);

      const conversation = res.data.conversation;
      const loadedMessages = conversation.messages ?? [];

      setActiveConversationId(res.data.conversation_id);
      setMessages(loadedMessages);
      onConversationCreated?.(conversation);
      onMessagesUpdated?.(res.data.conversation_id, loadedMessages);
    } catch (err: unknown) {
      const response = err as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Unable to send your message. Please try again.');
      setMessages((prev) => prev.filter((m) => m.id !== optimisticUserMessage.id));
      setInput(trimmed);
    } finally {
      setLoading(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  return (
    <div className={`flex flex-col h-full min-h-[420px] bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden ${className}`}>
      <div className="px-4 sm:px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-bold text-slate-900">MasterInTech AI</h2>
          <p className="text-[11px] text-slate-500">Ask about courses, programming, and career skills</p>
        </div>
        {loading && (
          <span className="text-[11px] font-semibold text-blue-600 flex items-center gap-1.5">
            <span className="w-3 h-3 border-2 border-blue-600 border-t-transparent rounded-full animate-spin" />
            Thinking...
          </span>
        )}
      </div>

      {error && (
        <div className="px-4 py-2 bg-red-50 border-b border-red-100 text-red-700 text-xs font-medium">
          {error}
        </div>
      )}

      <div className="flex-1 overflow-y-auto px-4 sm:px-5 py-4 space-y-3 bg-slate-50/60">
        {loadingConversation ? (
          <div className="flex items-center justify-center py-16 text-slate-500 text-sm">
            <span className="w-4 h-4 border-2 border-blue-600 border-t-transparent rounded-full animate-spin mr-2" />
            Loading conversation...
          </div>
        ) : messages.length === 0 ? (
          <div className="text-center py-16 px-4">
            <div className="w-12 h-12 rounded-2xl bg-blue-100 text-blue-600 font-black text-lg flex items-center justify-center mx-auto mb-3">
              AI
            </div>
            <h3 className="text-sm font-bold text-slate-900">Start a conversation</h3>
            <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
              Ask about Python, cloud, data science, interview prep, or how to navigate your learning path.
            </p>
          </div>
        ) : (
          messages.map((message) => (
            <div
              key={message.id}
              className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}
            >
              <div
                className={`max-w-[85%] sm:max-w-[75%] rounded-2xl px-3.5 py-2.5 text-xs sm:text-sm leading-relaxed ${
                  message.role === 'user'
                    ? 'bg-blue-600 text-white rounded-br-md'
                    : 'bg-white border border-slate-200 text-slate-800 rounded-bl-md'
                }`}
              >
                <p className="whitespace-pre-wrap break-words">{message.content}</p>
              </div>
            </div>
          ))
        )}
        <div ref={bottomRef} />
      </div>

      <div className="border-t border-slate-200 p-3 sm:p-4 bg-white">
        <div className="flex items-end gap-2">
          <textarea
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={handleKeyDown}
            rows={2}
            placeholder="Type your question..."
            disabled={loading}
            className="flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-xs sm:text-sm outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:opacity-60"
          />
          <button
            type="button"
            onClick={handleSend}
            disabled={loading || !input.trim()}
            className="shrink-0 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold disabled:opacity-50 transition"
          >
            Send
          </button>
        </div>
        <p className="text-[10px] text-slate-400 mt-2">Press Enter to send, Shift+Enter for a new line.</p>
      </div>
    </div>
  );
}
