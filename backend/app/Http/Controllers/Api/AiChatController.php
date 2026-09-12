<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiOrchestratorService;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatController extends Controller
{
    public function chat(Request $request, AiOrchestratorService $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:'.config('ai.max_message_length', 4000)],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_conversations,id'],
        ]);

        try {
            $result = $orchestrator->chat(
                $request->user(),
                $validated['message'],
                $validated['conversation_id'] ?? null,
            );
        } catch (AiProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->status);
        }

        return response()->json([
            'conversation_id' => $result['conversation']->id,
            'reply' => $result['reply'],
            'conversation' => $this->formatConversation($result['conversation']),
        ]);
    }

    public function index(Request $request, AiOrchestratorService $orchestrator): JsonResponse
    {
        $conversations = $orchestrator->listConversations($request->user());

        return response()->json([
            'data' => $conversations->map(fn ($conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'provider' => $conversation->provider,
                'model' => $conversation->model,
                'status' => $conversation->status,
                'messages_count' => $conversation->messages_count,
                'updated_at' => $conversation->updated_at?->toIso8601String(),
                'created_at' => $conversation->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function show(Request $request, int $conversation, AiOrchestratorService $orchestrator): JsonResponse
    {
        $record = $orchestrator->getConversation($request->user(), $conversation);

        return response()->json($this->formatConversation($record));
    }

    private function formatConversation(\App\Models\AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'provider' => $conversation->provider,
            'model' => $conversation->model,
            'status' => $conversation->status,
            'updated_at' => $conversation->updated_at?->toIso8601String(),
            'created_at' => $conversation->created_at?->toIso8601String(),
            'messages' => $conversation->messages->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
