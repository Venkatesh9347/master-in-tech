<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Data\LlmChatResult;
use Illuminate\Support\Str;

class StubLlmProvider implements LlmProviderInterface
{
    public function getName(): string
    {
        return 'stub';
    }

    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $lastUserMessage = '';

        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') === 'user') {
                $lastUserMessage = (string) ($message['content'] ?? '');
                break;
            }
        }

        $preview = trim($lastUserMessage) !== ''
            ? Str::limit(trim($lastUserMessage), 120)
            : 'your question';

        $content = "MasterInTech AI (stub): I received \"{$preview}\". "
            .'Configure OPENAI_API_KEY and AI_PROVIDER=openai on the server for live LLM responses. '
            .'I can help with programming concepts, course guidance, and career preparation once connected.';

        return new LlmChatResult(
            content: $content,
            model: 'stub-local',
            metadata: [
                'provider' => $this->getName(),
                'stub' => true,
            ],
        );
    }
}
