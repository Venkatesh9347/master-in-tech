<?php

namespace App\Services\Ai\Data;

class LlmChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $model = null,
        public readonly array $metadata = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?string $requestId = null,
        public readonly ?string $finishReason = null,
    ) {}
}
