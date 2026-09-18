<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Data\LlmChatResult;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini provider.
 *
 * Talks to the Generative Language `generateContent` endpoint over HTTPS.
 * The API key travels as a query parameter per Google's convention and is
 * never stored, logged, or returned — only read from server config.
 */
class GeminiProvider implements LlmProviderInterface
{
    public function getName(): string
    {
        return 'gemini';
    }

    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $apiKey = config('ai.providers.gemini.api_key');
        if (empty($apiKey)) {
            throw new AiProviderException(
                'Gemini API key is not configured on the server.',
                'AI_NOT_CONFIGURED',
                503
            );
        }

        $model = $options['model'] ?? config('ai.providers.gemini.model');
        $baseUrl = rtrim((string) config('ai.providers.gemini.base_url'), '/');
        $timeout = (int) config('ai.providers.gemini.timeout', 60);

        $payload = $this->buildPayload($messages, $options);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(10)
                ->acceptJson()
                ->post("{$baseUrl}/models/{$model}:generateContent", array_merge(
                    ['key' => $apiKey],
                    $payload
                ));
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

        $parts = data_get($response->json(), 'candidates.0.content.parts', []);
        $content = $this->extractText($parts);

        if ($content === null) {
            throw new AiProviderException(
                'The AI provider returned an empty response.',
                'AI_EMPTY_RESPONSE',
                502
            );
        }

        return new LlmChatResult(
            content: $content,
            model: $model,
            metadata: [
                'provider' => $this->getName(),
            ],
            inputTokens: $this->intOrNull(data_get($response->json(), 'usageMetadata.promptTokenCount')),
            outputTokens: $this->intOrNull(data_get($response->json(), 'usageMetadata.candidatesTokenCount')),
            totalTokens: $this->intOrNull(data_get($response->json(), 'usageMetadata.totalTokenCount')),
            finishReason: $this->stringOrNull(data_get($response->json(), 'candidates.0.finishReason')),
        );
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function buildPayload(array $messages, array $options): array
    {
        $systemTexts = [];
        $contents = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $text = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                if (trim($text) !== '') {
                    $systemTexts[] = $text;
                }

                continue;
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        $payload = ['contents' => $contents];

        if ($systemTexts !== []) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => implode("\n\n", $systemTexts)]],
            ];
        }

        $maxTokens = $options['max_tokens'] ?? config('ai.max_tokens');
        if (is_numeric($maxTokens) && (int) $maxTokens > 0) {
            $payload['generationConfig'] = ['maxOutputTokens' => (int) $maxTokens];
        }

        return $payload;
    }

    private function extractText(mixed $parts): ?string
    {
        if (! is_array($parts)) {
            return null;
        }

        $texts = [];

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $texts[] = $part['text'];
            }
        }

        $content = trim(implode('', $texts));

        return $content !== '' ? $content : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
