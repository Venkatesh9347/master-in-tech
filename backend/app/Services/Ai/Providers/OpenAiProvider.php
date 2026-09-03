<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Data\LlmChatResult;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenAiProvider implements LlmProviderInterface
{
    public function getName(): string
    {
        return 'openai';
    }

    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $apiKey = config('ai.providers.openai.api_key');
        if (empty($apiKey)) {
            throw new AiProviderException(
                'OpenAI API key is not configured on the server.',
                'AI_NOT_CONFIGURED',
                503
            );
        }

        $model = $options['model'] ?? config('ai.providers.openai.model');
        $maxTokens = $options['max_tokens'] ?? config('ai.max_tokens');
        $baseUrl = rtrim((string) config('ai.providers.openai.base_url'), '/');
        $timeout = (int) config('ai.providers.openai.timeout', 60);

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->acceptJson()
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                ]);
        } catch (ConnectionException $e) {
            throw new AiProviderException(
                'Unable to reach the AI provider. Check server network/SSL configuration.',
                'AI_PROVIDER_CONNECTION_ERROR',
                503
            );
        }

        if ($response->failed()) {
            $errorMessage = data_get($response->json(), 'error.message')
                ?? 'The AI provider returned an error.';

            throw new AiProviderException(
                $errorMessage,
                'AI_PROVIDER_ERROR',
                $response->status() >= 500 ? 503 : 422
            );
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new AiProviderException(
                'The AI provider returned an empty response.',
                'AI_EMPTY_RESPONSE',
                502
            );
        }

        return new LlmChatResult(
            content: trim($content),
            model: data_get($response->json(), 'model', $model),
            metadata: [
                'provider' => $this->getName(),
                'usage' => data_get($response->json(), 'usage'),
            ],
        );
    }
}
