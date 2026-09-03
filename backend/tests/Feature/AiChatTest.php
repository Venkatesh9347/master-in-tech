<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
