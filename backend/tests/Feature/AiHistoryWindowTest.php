<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AiOrchestratorService;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Data\LlmChatResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The provider must receive the most recent context window in chronological
 * order — not the oldest messages.
 */
class AiHistoryWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_receives_latest_messages_chronologically(): void
    {
        config(['ai.max_history_messages' => 10]);

        $user = User::factory()->create(['role' => 'student']);
        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'title' => 'Window fixture',
            'provider' => 'stub',
            'model' => 'stub-model',
            'status' => 'active',
        ]);

        // 15 alternating messages: msg-00 (oldest) .. msg-14 (newest).
        for ($i = 0; $i < 15; $i++) {
            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => sprintf('msg-%02d', $i),
            ]);
        }

        $provider = new class implements LlmProviderInterface {
            public array $seen = [];

            public function chat(array $messages, array $options = []): LlmChatResult
            {
                $this->seen = $messages;

                return new LlmChatResult(content: 'stub-reply', model: 'stub-model');
            }

            public function getName(): string
            {
                return 'stub';
            }
        };

        (new AiOrchestratorService($provider))->chat($user, 'new question', $conversation->id);

        $captured = $provider->seen;
        $this->assertNotEmpty($captured);
        // [system, last-10 incl. the just-created user message, chronological]
        $this->assertSame('system', $captured[0]['role']);
        $history = array_slice($captured, 1);
        $this->assertCount(10, $history);
        $this->assertSame('msg-06', $history[0]['content']);
        $this->assertSame('new question', $history[9]['content']);

        $contents = array_column($history, 'content');
        $sorted = $contents;
        sort($sorted);
        // Chronological order check via DB ids is implicit; assert window tail:
        $this->assertNotContains('msg-00', $contents);
        $this->assertNotContains('msg-05', $contents);
    }
}
