<?php

namespace Tests\Feature;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Providers\StubLlmProvider;
use Tests\TestCase;

class AiProviderConfigurationTest extends TestCase
{
    public function test_stub_provider_is_bound_by_default(): void
    {
        config(['ai.default_provider' => 'stub']);

        $this->assertInstanceOf(StubLlmProvider::class, app(LlmProviderInterface::class));
    }

    public function test_openai_provider_is_bound_when_configured(): void
    {
        config(['ai.default_provider' => 'openai']);

        $this->assertInstanceOf(OpenAiProvider::class, app(LlmProviderInterface::class));
    }

    public function test_unsupported_provider_fails_loudly(): void
    {
        config(['ai.default_provider' => 'ollama']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ollama');

        app(LlmProviderInterface::class);
    }

    public function test_typoed_provider_fails_loudly(): void
    {
        config(['ai.default_provider' => 'open-ai']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported AI_PROVIDER');

        app(LlmProviderInterface::class);
    }
}