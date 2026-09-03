export interface AiMessage {
  id: number;
  role: 'user' | 'assistant' | 'system';
  content: string;
  created_at?: string;
}

export interface AiConversation {
  id: number;
  title: string | null;
  provider?: string;
  model?: string | null;
  status?: string;
  messages_count?: number;
  updated_at?: string;
  created_at?: string;
  messages?: AiMessage[];
}

export interface AiChatResponse {
  conversation_id: number;
  reply: string;
  conversation: AiConversation;
}
