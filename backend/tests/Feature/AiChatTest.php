<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    private function createStudent(): User
    {
        return User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    public function test_ai_chat_requires_authentication(): void
    {
        $this->postJson('/api/ai/chat', ['message' => 'Hello'])
            ->assertUnauthorized();
    }

    public function test_ai_chat_creates_conversation_and_persists_messages(): void
    {
        config(['ai.enabled' => true]);
        $student = $this->createStudent();
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/ai/chat', [
            'message' => 'What is Python?',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'conversation_id',
                'reply',
                'conversation' => [
                    'id',
                    'title',
                    'messages',
                ],
            ]);

        $conversationId = $response->json('conversation_id');

        $this->assertDatabaseHas('ai_conversations', [
            'id' => $conversationId,
            'user_id' => $student->id,
        ]);

        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'What is Python?',
        ]);

        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversationId,
            'role' => 'assistant',
        ]);
    }

    public function test_ai_chat_continues_existing_conversation(): void
    {
        config(['ai.enabled' => true]);
        $student = $this->createStudent();
        Sanctum::actingAs($student);

        $conversation = AiConversation::create([
            'user_id' => $student->id,
            'title' => 'Existing chat',
            'provider' => 'stub',
            'model' => 'stub-local',
            'status' => 'active',
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'First question',
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'First answer',
        ]);

        $response = $this->postJson('/api/ai/chat', [
            'message' => 'Follow-up question',
            'conversation_id' => $conversation->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('conversation_id', $conversation->id);

        $this->assertEquals(4, AiMessage::where('ai_conversation_id', $conversation->id)->count());
    }

    public function test_ai_conversations_index_returns_only_current_user_records(): void
    {
        $student = $this->createStudent();
        $other = $this->createStudent();

        AiConversation::create([
            'user_id' => $student->id,
            'title' => 'Mine',
            'provider' => 'stub',
            'status' => 'active',
        ]);

        AiConversation::create([
            'user_id' => $other->id,
            'title' => 'Not mine',
            'provider' => 'stub',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/ai/conversations');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_ai_conversation_show_is_scoped_to_owner(): void
    {
        $student = $this->createStudent();
        $other = $this->createStudent();

        $conversation = AiConversation::create([
            'user_id' => $other->id,
            'title' => 'Private',
            'provider' => 'stub',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/ai/conversations/'.$conversation->id)
            ->assertNotFound();
    }

    public function test_ai_chat_validates_message(): void
    {
        $student = $this->createStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/ai/chat', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    /* Kill switch: disabled legacy chat invokes no provider and records nothing. */

    public function test_ai_chat_disabled_returns_disabled_without_provider_call(): void
    {
        config([
            'ai.enabled' => false,
            'ai.providers.openai.api_key' => 'sk-test-secret-never-leaked',
            'ai.providers.gemini.api_key' => 'gemini-test-secret-never-leaked',
            'ai.providers.anthropic.api_key' => 'anthropic-test-secret-never-leaked',
        ]);
        Http::preventStrayRequests();
        Http::fake();

        $student = $this->createStudent();
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/ai/chat', ['message' => 'What is Python?']);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'AI_DISABLED');

        $body = $response->getContent();
        foreach (['sk-test-secret-never-leaked', 'gemini-test-secret-never-leaked', 'anthropic-test-secret-never-leaked'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usages', 0);
        $this->assertDatabaseCount('ai_conversations', 0);
        $this->assertDatabaseCount('ai_messages', 0);
    }

    /* Enabled legacy chat: one provider call, one usage row, history preserved. */

    public function test_ai_chat_enabled_makes_exactly_one_provider_call_and_usage_row(): void
    {
        config([
            'ai.enabled' => true,
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'chatcmpl-legacy-1',
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => 'Python is a language.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12, 'total_tokens' => 42],
        ], 200)]);

        $student = $this->createStudent();
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/ai/chat', ['message' => 'What is Python?']);

        $response->assertOk()
            ->assertJsonPath('reply', 'Python is a language.');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_usages', 1);
        $this->assertDatabaseHas('ai_usages', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'operation' => 'chat',
            'user_id' => $student->id,
            'request_id' => 'chatcmpl-legacy-1',
            'input_tokens' => 30,
            'output_tokens' => 12,
            'total_tokens' => 42,
            'success' => true,
            'error_code' => null,
        ]);

        // Conversation persistence unchanged: user + assistant messages stored.
        $conversationId = $response->json('conversation_id');
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'What is Python?',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => 'Python is a language.',
        ]);
    }

    public function test_ai_chat_enabled_without_usage_block_still_records(): void
    {
        config([
            'ai.enabled' => true,
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => 'Hi.']]],
        ], 200)]);

        $student = $this->createStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/ai/chat', ['message' => 'Hi?'])->assertOk();

        $this->assertDatabaseHas('ai_usages', [
            'provider' => 'openai',
            'success' => true,
            'input_tokens' => null,
            'estimated_cost' => null,
        ]);
    }

    /* Failure path: normalized error, failure usage row, no secret leakage. */

    public function test_ai_chat_provider_failure_is_normalized_and_recorded(): void
    {
        config([
            'ai.enabled' => true,
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-secret-failure-path',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response('Server exploded', 500)]);

        $student = $this->createStudent();
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/ai/chat', ['message' => 'What is Python?']);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'AI_PROVIDER_ERROR');

        $this->assertStringNotContainsString('sk-test-secret-failure-path', $response->getContent());
        $this->assertDatabaseHas('ai_usages', [
            'provider' => 'openai',
            'user_id' => $student->id,
            'success' => false,
            'error_code' => 'AI_PROVIDER_ERROR',
        ]);
    }
}
