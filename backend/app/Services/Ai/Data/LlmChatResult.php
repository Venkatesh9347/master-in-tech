<?php

namespace App\Services\Ai\Data;

class LlmChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $model = null,
        public readonly array $metadata = [],
    ) {}
}
