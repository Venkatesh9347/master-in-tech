<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OllamaProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Providers\StubLlmProvider;

/**
 * Single resolution map from provider name to adapter.
 *
 * Unknown names fail loudly (RuntimeException) instead of silently falling
 * back, so a typo can never route traffic somewhere unexpected. The AI
 * gateway pre-validates names and raises a controlled AiProviderException
 * instead; this factory exception only surfaces on the direct container
 * resolution path used by the legacy orchestrator binding.
 */
class AiProviderFactory
{
    public const SUPPORTED_PROVIDERS = ['stub', 'openai', 'gemini', 'anthropic', 'ollama'];

    public static function make(string $provider): LlmProviderInterface
    {
        return match ($provider) {
            'stub' => new StubLlmProvider(),
            'openai' => new OpenAiProvider(),
            'gemini' => new GeminiProvider(),
            'anthropic' => new AnthropicProvider(),
            'ollama' => new OllamaProvider(),
            default => throw new \RuntimeException(
                "Unsupported AI_PROVIDER [{$provider}]. Supported providers: \""
                .implode('", "', self::SUPPORTED_PROVIDERS).'".'
            ),
        };
    }

    public static function isSupported(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED_PROVIDERS, true);
    }
}
