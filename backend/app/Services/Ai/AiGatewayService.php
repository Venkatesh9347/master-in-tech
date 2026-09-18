<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Data\LlmChatResult;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * AI-0 foundation gateway: the only entry point future AI features use.
 *
 * Pipeline per request: enabled gate → provider resolution → model
 * resolution → single provider attempt (explicit timeout, no retries) →
 * normalized result → usage record → cost estimate. Provider failures
 * surface as controlled AiProviderException errors; unexpected throwables
 * are wrapped generically so internals and secrets never leak.
 *
 * Non-secret runtime overrides live in website settings (group "ai"):
 * `ai.enabled`, `ai.default_provider`, `ai.default_model`. API keys are
 * server environment only and are never read from settings. The legacy
 * conversation orchestrator predates this gateway and is intentionally
 * untouched; new features must call the gateway, never providers directly,
 * and never from models, migrations, middleware, auth, payments,
 * enrollments, or certificate issuance.
 */
class AiGatewayService
{
    public function __construct(
        private readonly AiCostCalculator $costs,
    ) {}

    /**
     * Single-turn text generation.
     *
     * @param  array{provider?: string, model?: string, max_tokens?: int, operation?: string, user?: User|int|null}  $options
     */
    public function generate(string $prompt, array $options = []): AiResult
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new AiProviderException('Prompt cannot be empty.', 'AI_VALIDATION_ERROR', 422);
        }

        return $this->execute(
            [['role' => 'user', 'content' => $prompt]],
            $options['operation'] ?? 'generate',
            $options
        );
    }

    /**
     * Multi-turn chat.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{provider?: string, model?: string, max_tokens?: int, operation?: string, user?: User|int|null}  $options
     */
    public function chat(array $messages, array $options = []): AiResult
    {
        $messages = array_values(array_filter(
            $messages,
            fn ($message) => is_array($message) && trim((string) ($message['content'] ?? '')) !== ''
        ));

        if ($messages === []) {
            throw new AiProviderException('Messages cannot be empty.', 'AI_VALIDATION_ERROR', 422);
        }

        return $this->execute($messages, $options['operation'] ?? 'chat', $options);
    }

    /**
     * Enforce the kill switch. Call before doing any billable or persistent
     * work so disabled requests leave no trace (no provider contact, no
     * usage row, and — for callers like the chat orchestrator — no partial
     * records created ahead of the provider call).
     */
    public function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new AiProviderException(
                'AI features are disabled on this server.',
                'AI_DISABLED',
                503
            );
        }
    }

    /**
     * Effective kill switch: website setting `ai.enabled` wins when present,
     * otherwise the AI_ENABLED environment default (false).
     */
    public function isEnabled(): bool
    {
        $override = WebsiteSetting::get('ai.enabled');

        if ($override === null) {
            return (bool) config('ai.enabled', false);
        }

        return $this->toBool($override);
    }

    /**
     * Effective default provider (explicit per-call value wins elsewhere).
     */
    public function defaultProvider(): string
    {
        $override = WebsiteSetting::get('ai.default_provider');

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return (string) config('ai.default_provider', 'stub');
    }

    /**
     * Effective model for a provider: provider-specific config first, then
     * the global override (setting wins when present, else AI_DEFAULT_MODEL).
     */
    public function defaultModelFor(string $provider): ?string
    {
        $configured = config("ai.providers.{$provider}.model");

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $override = WebsiteSetting::get('ai.default_model');

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $global = config('ai.default_model');

        return is_string($global) && trim($global) !== '' ? trim($global) : null;
    }

    /**
     * Whether a provider has what it needs to attempt a request. Keyed
     * providers require a configured API key; stub never needs anything;
     * ollama only needs its base URL.
     */
    public function isProviderConfigured(string $provider): bool
    {
        return match ($provider) {
            'stub' => true,
            'ollama' => trim((string) config('ai.providers.ollama.base_url', '')) !== '',
            'openai', 'gemini', 'anthropic' => trim((string) config("ai.providers.{$provider}.api_key", '')) !== '',
            default => false,
        };
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function execute(array $messages, string $operation, array $options): AiResult
    {
        $this->ensureEnabled();

        $providerName = $options['provider'] ?? $this->defaultProvider();
        $providerName = is_string($providerName) ? trim($providerName) : '';

        if (! AiProviderFactory::isSupported($providerName)) {
            throw new AiProviderException(
                "Unknown AI provider [{$providerName}].",
                'AI_UNKNOWN_PROVIDER',
                503
            );
        }

        $provider = AiProviderFactory::make($providerName);

        $model = $options['model'] ?? $this->defaultModelFor($providerName);
        $model = is_string($model) && trim($model) !== '' ? trim($model) : null;

        $startedAt = microtime(true);

        try {
            $result = $provider->chat($messages, [
                'model' => $model,
                'max_tokens' => $options['max_tokens'] ?? config('ai.max_tokens'),
            ]);
        } catch (AiProviderException $e) {
            $this->recordUsage($providerName, $model, $operation, $options, $startedAt, null, $e->errorCode);

            throw $e;
        } catch (\Throwable $e) {
            report($e);

            $this->recordUsage($providerName, $model, $operation, $options, $startedAt, null, 'AI_PROVIDER_ERROR');

            throw new AiProviderException(
                'The AI provider request failed unexpectedly.',
                'AI_PROVIDER_ERROR',
                503
            );
        }

        return $this->succeed($provider, $providerName, $model, $operation, $messages, $options, $startedAt, $result);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function succeed(
        LlmProviderInterface $provider,
        string $providerName,
        ?string $model,
        string $operation,
        array $messages,
        array $options,
        float $startedAt,
        LlmChatResult $result,
    ): AiResult {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $estimate = $this->costs->estimate(
            $providerName,
            $result->model ?? $model,
            $result->inputTokens,
            $result->outputTokens
        );

        $usage = $this->recordUsage(
            $providerName,
            $result->model ?? $model,
            $operation,
            $options,
            $startedAt,
            $result,
            null,
            $estimate
        );

        return AiResult::fromChatResult(
            $provider->getName(),
            $result,
            $latencyMs,
            $usage->id,
            $estimate['cost'] ?? null,
            $estimate['currency'] ?? null,
        );
    }

    /**
     * @param  array{user?: User|int|null}  $options
     * @param  array{cost: string, currency: string}|null  $estimate
     */
    private function recordUsage(
        string $providerName,
        ?string $model,
        string $operation,
        array $options,
        float $startedAt,
        ?LlmChatResult $result,
        ?string $errorCode,
        ?array $estimate = null,
    ): AiUsage {
        $userId = $options['user'] ?? null;
        $userId = $userId instanceof User ? $userId->id : (is_numeric($userId) ? (int) $userId : null);

        return AiUsage::create([
            'provider' => $providerName,
            'model' => $result?->model ?? $model,
            'operation' => substr((string) $operation, 0, 50),
            'user_id' => $userId,
            'request_id' => $result?->requestId !== null ? substr($result->requestId, 0, 100) : null,
            'input_tokens' => $result?->inputTokens,
            'output_tokens' => $result?->outputTokens,
            'total_tokens' => $result?->totalTokens,
            'estimated_cost' => $estimate['cost'] ?? null,
            'currency' => $estimate['currency'] ?? 'USD',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'success' => $errorCode === null,
            'error_code' => $errorCode !== null ? substr($errorCode, 0, 100) : null,
            'request_hash' => hash('sha256', implode('|', [
                $providerName,
                (string) ($result?->model ?? $model),
                (string) $operation,
            ])),
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
