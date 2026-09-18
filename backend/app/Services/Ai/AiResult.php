<?php

namespace App\Services\Ai;

use App\Services\Ai\Data\LlmChatResult;

/**
 * Normalized application-facing AI result.
 *
 * Provider-specific response shapes never leave the provider adapters:
 * future features consume this object (or its array form) only.
 */
class AiResult
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $model,
        public readonly string $text,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?string $requestId = null,
        public readonly ?int $latencyMs = null,
        public readonly ?string $finishReason = null,
        public readonly ?int $usageId = null,
        public readonly ?string $estimatedCost = null,
        public readonly ?string $currency = null,
    ) {}

    public static function fromChatResult(
        string $provider,
        LlmChatResult $result,
        ?int $latencyMs = null,
        ?int $usageId = null,
        ?string $estimatedCost = null,
        ?string $currency = null,
    ): self {
        return new self(
            provider: $provider,
            model: $result->model,
            text: $result->content,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            totalTokens: $result->totalTokens,
            requestId: $result->requestId,
            latencyMs: $latencyMs,
            finishReason: $result->finishReason,
            usageId: $usageId,
            estimatedCost: $estimatedCost,
            currency: $currency,
        );
    }

    /**
     * Safe array form: usage metadata only, never prompts, keys, or raw
     * provider payloads.
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'text' => $this->text,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'request_id' => $this->requestId,
            'latency_ms' => $this->latencyMs,
            'finish_reason' => $this->finishReason,
            'usage_id' => $this->usageId,
            'estimated_cost' => $this->estimatedCost,
            'currency' => $this->currency,
        ];
    }
}
