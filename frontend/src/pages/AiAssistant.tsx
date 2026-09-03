import { useEffect, useState } from 'react';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import AiChatPanel from '../components/ai/AiChatPanel';
import API from '../services/api';
import type { AiConversation } from '../types/ai';

export default function AiAssistant() {
  const [conversations, setConversations] = useState<AiConversation[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [loadingList, setLoadingList] = useState(true);
  const [listError, setListError] = useState('');

  const loadConversations = () => {
    setLoadingList(true);
    setListError('');

    API.get<{ data: AiConversation[] }>('/ai/conversations')
      .then((res) => {
        const rows = res.data.data ?? [];
        setConversations(rows);
        if (!selectedId && rows.length > 0) {
          setSelectedId(rows[0].id);
        }
      })
      .catch(() => {
        setListError('Unable to load your AI conversations.');
      })
      .finally(() => setLoadingList(false));
  };

  useEffect(() => {
    loadConversations();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleConversationCreated = (conversation: AiConversation) => {
    setSelectedId(conversation.id);
    setConversations((prev) => {
      const others = prev.filter((c) => c.id !== conversation.id);
      return [conversation, ...others];
    });
  };

  const handleNewConversation = () => {
    setSelectedId(null);
  };

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <Navbar />

      <main className="flex-1 max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
        <div className="mb-5 sm:mb-6">
          <h1 className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">AI Learning Assistant</h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Get help with programming concepts, course guidance, and career preparation.
          </p>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-[280px_minmax(0,1fr)] gap-4 sm:gap-5 min-h-[70vh]">
          <aside className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden flex flex-col min-h-[280px] lg:min-h-0">
            <div className="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-2">
              <h2 className="text-xs font-bold uppercase tracking-wider text-slate-600">Conversations</h2>
              <button
                type="button"
                onClick={handleNewConversation}
                className="text-[11px] font-bold text-blue-600 hover:text-blue-700"
              >
                + New
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-2 space-y-1">
              {loadingList ? (
                <div className="flex items-center justify-center py-10 text-slate-500 text-xs">
                  <span className="w-3.5 h-3.5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin mr-2" />
                  Loading...
                </div>
              ) : listError ? (
                <p className="text-xs text-red-600 px-2 py-3">{listError}</p>
              ) : conversations.length === 0 ? (
                <p className="text-xs text-slate-500 px-2 py-3">No conversations yet. Start chatting on the right.</p>
              ) : (
                conversations.map((conversation) => (
                  <button
                    key={conversation.id}
                    type="button"
                    onClick={() => setSelectedId(conversation.id)}
                    className={`w-full text-left px-3 py-2.5 rounded-xl border transition ${
                      selectedId === conversation.id
                        ? 'bg-blue-50 border-blue-200 text-blue-800'
                        : 'bg-white border-transparent hover:bg-slate-50 text-slate-700'
                    }`}
                  >
                    <p className="text-xs font-semibold truncate">{conversation.title || 'Untitled conversation'}</p>
                    <p className="text-[10px] text-slate-400 mt-0.5">
                      {conversation.messages_count ?? conversation.messages?.length ?? 0} messages
                    </p>
                  </button>
                ))
              )}
            </div>
          </aside>

          <section className="min-h-[420px] lg:min-h-0">
            <AiChatPanel
              conversationId={selectedId}
              onConversationCreated={handleConversationCreated}
            />
          </section>
        </div>
      </main>

      <Footer />
    </div>
  );
}
