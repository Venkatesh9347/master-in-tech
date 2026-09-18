<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Str;

class AiOrchestratorService
{
    /**
     * The injected provider pins request attribution (provider name/model);
     * the actual provider invocation always goes through the gateway so the
     * AI_ENABLED kill switch, usage recording, and error semantics apply
     * exactly once — the orchestrator never calls a provider directly.
     */
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly AiGatewayService $gateway,
    ) {}

    /**
     * @return array{conversation: AiConversation, reply: string, user_message: AiMessage, assistant_message: AiMessage}
     */
    public function chat(User $user, string $message, ?int $conversationId = null): array
    {
        $message = trim($message);

        if ($message === '') {
            throw new AiProviderException('Message cannot be empty.', 'AI_VALIDATION_ERROR', 422);
        }

        // Kill switch before any persistence: a disabled request must leave
        // no conversation, no messages, no provider contact, and no usage row.
        $this->gateway->ensureEnabled();

        $conversation = $this->resolveConversation($user, $message, $conversationId);

        $userMessage = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
        ]);

        $providerMessages = $this->buildProviderMessages($conversation);

        // Single gateway-mediated provider invocation: enforces AI_ENABLED,
        // records exactly one AiUsage row, and normalizes errors. The
        // provider/model are pinned to this conversation's attribution so
        // resolution behavior matches the previous direct call.
        $result = $this->gateway->chat($providerMessages, [
            'provider' => $this->provider->getName(),
            'model' => $conversation->model,
            'max_tokens' => config('ai.max_tokens'),
            'operation' => 'chat',
            'user' => $user,
        ]);

        $assistantMessage = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $result->text,
            'metadata' => [
                'provider' => $result->provider,
                'ai_usage_id' => $result->usageId,
            ],
        ]);

        if (empty($conversation->title)) {
            $conversation->title = Str::limit($message, 80);
        }

        $conversation->provider = $this->provider->getName();
        if ($result->model) {
            $conversation->model = $result->model;
        }
        $conversation->touch();

        return [
            'conversation' => $conversation->load('messages'),
            'reply' => $result->text,
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
        ];
    }

    public function listConversations(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return AiConversation::query()
            ->where('user_id', $user->id)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function getConversation(User $user, int $conversationId): AiConversation
    {
        return AiConversation::query()
            ->where('user_id', $user->id)
            ->with('messages')
            ->findOrFail($conversationId);
    }

    private function resolveConversation(User $user, string $message, ?int $conversationId): AiConversation
    {
        if ($conversationId) {
            return AiConversation::query()
                ->where('user_id', $user->id)
                ->findOrFail($conversationId);
        }

        return AiConversation::create([
            'user_id' => $user->id,
            'title' => Str::limit($message, 80),
            'provider' => $this->provider->getName(),
            'model' => config('ai.providers.openai.model'),
            'status' => 'active',
        ]);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildProviderMessages(AiConversation $conversation): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => (string) config('ai.system_prompt'),
            ],
        ];

        $historyLimit = (int) config('ai.max_history_messages', 40);

        $history = $conversation->messages()
            ->orderBy('id')
            ->when($historyLimit > 0, fn ($query) => $query->limit($historyLimit))
            ->get();

        foreach ($history as $message) {
            if (in_array($message->role, ['user', 'assistant'], true)) {
                $messages[] = [
                    'role' => $message->role,
                    'content' => $message->content,
                ];
            }
        }

        return $messages;
    }
}
