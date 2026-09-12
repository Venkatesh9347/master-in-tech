<?php

namespace Tests\Feature;

use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\OllamaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ollama stays optional: no daemon required for the app or the suite.
 * Unreachable daemons fail gracefully (503-style exception, never a hang
 * or 500); HTTP-level success parses the Ollama chat schema.
 */
class OllamaProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unreachable_daemon_fails_gracefully(): void
    {
        // Nothing listens here in CI: must raise a coded exception, not hang.
        config([
            'ai.providers.ollama.base_url' => 'http://127.0.0.1:9',
            'ai.providers.ollama.timeout' => 2,
        ]);

        $this->expectException(AiProviderException::class);

        try {
            (new OllamaProvider())->chat([['role' => 'user', 'content' => 'hi']]);
        } catch (AiProviderException $e) {
            $this->assertSame('AI_PROVIDER_CONNECTION_ERROR', $e->errorCode);
            $this->assertSame(503, $e->status);
            throw $e;
        }
    }

    public function test_chat_parses_ollama_schema(): void
    {
        Http::fake([
            '*' => Http::response([
                'model' => 'llama3.1',
                'message' => ['role' => 'assistant', 'content' => '  Hello from local.  '],
            ], 200),
        ]);

        $result = (new OllamaProvider())->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello from local.', $result->content);
        $this->assertSame('llama3.1', $result->model);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['stream'] ?? null) === false
                && ($body['messages'][0]['role'] ?? null) === 'user'
                && str_ends_with((string) $request->url(), '/api/chat');
        });
    }

    public function test_provider_error_maps_to_422_or_503(): void
    {
        Http::fake(['*' => Http::response(['error' => 'model not found'], 404)]);

        try {
            (new OllamaProvider())->chat([['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_empty_reply_maps_to_502(): void
    {
        Http::fake(['*' => Http::response(['model' => 'x', 'message' => ['role' => 'assistant', 'content' => '  ']], 200)]);

        $this->expectException(AiProviderException::class);
        (new OllamaProvider())->chat([['role' => 'user', 'content' => 'hi']]);
    }
}
