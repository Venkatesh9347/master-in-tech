<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Data\LlmChatResult;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Claude provider.
 *
 * Talks to the Messages API over HTTPS. The API key travels in the
 * `x-api-key` header and is never stored, logged, or returned — only read
 * from server config.
 */
class AnthropicProvider implements LlmProviderInterface
{
    public function getName(): string
    {
        return 'anthropic';
    }

    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $apiKey = config('ai.providers.anthropic.api_key');
        if (empty($apiKey)) {
            throw new AiProviderException(
                'Anthropic API key is not configured on the server.',
                'AI_NOT_CONFIGURED',
                503
            );
        }

        $model = $options['model'] ?? config('ai.providers.anthropic.model');
        $baseUrl = rtrim((string) config('ai.providers.anthropic.base_url'), '/');
        $timeout = (int) config('ai.providers.anthropic.timeout', 60);
        $maxTokens = $options['max_tokens'] ?? config('ai.max_tokens');

        [$system, $apiMessages] = $this->splitMessages($messages);

        try {
            $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout($timeout)
                ->connectTimeout(10)
                ->acceptJson()
                ->post("{$baseUrl}/messages", [
                    'model' => $model,
                    'max_tokens' => (int) $maxTokens,
                    'system' => $system,
                    'messages' => $apiMessages,
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

        $content = $this->extractText(data_get($response->json(), 'content'));

        if ($content === null) {
            throw new AiProviderException(
                'The AI provider returned an empty response.',
                'AI_EMPTY_RESPONSE',
                502
            );
        }

        return new LlmChatResult(
            content: $content,
            model: data_get($response->json(), 'model', $model),
            metadata: [
                'provider' => $this->getName(),
            ],
            inputTokens: $this->intOrNull(data_get($response->json(), 'usage.input_tokens')),
            outputTokens: $this->intOrNull(data_get($response->json(), 'usage.output_tokens')),
            requestId: $this->stringOrNull(data_get($response->json(), 'id')),
            finishReason: $this->stringOrNull(data_get($response->json(), 'stop_reason')),
        );
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{0: string, 1: array<int, array{role: string, content: string}>}
     */
    private function splitMessages(array $messages): array
    {
        $systemTexts = [];
        $apiMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $text = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                if (trim($text) !== '') {
                    $systemTexts[] = $text;
                }

                continue;
            }

            $apiMessages[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $text,
            ];
        }

        return [implode("\n\n", $systemTexts), $apiMessages];
    }

    private function extractText(mixed $blocks): ?string
    {
        if (! is_array($blocks)) {
            return null;
        }

        $texts = [];

        foreach ($blocks as $block) {
            if (is_array($block)
                && ($block['type'] ?? '') === 'text'
                && isset($block['text'])
                && is_string($block['text'])
            ) {
                $texts[] = $block['text'];
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
