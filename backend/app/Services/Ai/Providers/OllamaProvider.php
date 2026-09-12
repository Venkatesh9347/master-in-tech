<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Data\LlmChatResult;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Ollama local-model provider (optional).
 *
 * Talks to a self-hosted Ollama daemon over plain HTTP — no API key, no
 * account, no network egress required. The application never depends on
 * Ollama being installed: it is only resolved when AI_PROVIDER=ollama, the
 * default remains "stub", and an unreachable daemon surfaces as a graceful
 * 503 (AI_PROVIDER_CONNECTION_ERROR), never a 500 or a hang.
 */
class OllamaProvider implements LlmProviderInterface
{
    public function getName(): string
    {
        return 'ollama';
    }

    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $baseUrl = rtrim((string) config('ai.providers.ollama.base_url'), '/');
        $model = $options['model'] ?? config('ai.providers.ollama.model');
        $timeout = (int) config('ai.providers.ollama.timeout', 120);

        if ($baseUrl === '') {
            throw new AiProviderException(
                'Ollama base URL is not configured on the server.',
                'AI_NOT_CONFIGURED',
                503
            );
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post("{$baseUrl}/api/chat", [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                    'options' => [
                        'num_predict' => $options['max_tokens'] ?? config('ai.max_tokens'),
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AiProviderException(
                'Unable to reach the local Ollama daemon. Is Ollama installed and running?',
                'AI_PROVIDER_CONNECTION_ERROR',
                503
            );
        }

        if ($response->failed()) {
            throw new AiProviderException(
                'The local model returned an error.',
                'AI_PROVIDER_ERROR',
                $response->status() >= 500 ? 503 : 422
            );
        }

        $content = data_get($response->json(), 'message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new AiProviderException(
                'The local model returned an empty response.',
                'AI_EMPTY_RESPONSE',
                502
            );
        }

        return new LlmChatResult(
            content: trim($content),
            model: data_get($response->json(), 'model', $model),
            metadata: [
                'provider' => $this->getName(),
            ],
        );
    }
}
