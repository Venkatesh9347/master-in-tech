<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\LlmChatResult;

interface LlmProviderInterface
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, array $options = []): LlmChatResult;

    public function getName(): string;
}
