<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\Ai\AiCostCalculator;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AI-0 foundation gateway tests. Every provider interaction is mocked via
 * Http::fake() — no real paid API calls are made.
 */
class AiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private AiGatewayService $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config(['ai.enabled' => true]);

        $this->gateway = app(AiGatewayService::class);
    }

    private function gateway(): AiGatewayService
    {
        return app(AiGatewayService::class);
    }

    /* A. AI disabled */

    public function test_disabled_gateway_rejects_without_provider_call(): void
    {
        config([
            'ai.enabled' => false,
            'ai.providers.openai.api_key' => 'sk-test-secret-never-leaked',
        ]);
        Http::fake();

        try {
            $this->gateway()->generate('Hello?');
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_DISABLED', $e->errorCode);
            $this->assertSame(503, $e->status);
            $this->assertStringNotContainsString('sk-test-secret-never-leaked', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usages', 0);
    }

    public function test_disabled_gateway_records_nothing_and_leaks_nothing(): void
    {
        config(['ai.enabled' => false]);
        Http::fake();

        try {
            $this->gateway()->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_DISABLED', $e->errorCode);
        }

        $this->assertDatabaseCount('ai_usages', 0);
    }

    /* B. Provider resolution */

    public function test_explicit_provider_option_is_selected(): void
    {
        $result = $this->gateway()->generate('Hello?', ['provider' => 'stub']);

        $this->assertSame('stub', $result->provider);
        $this->assertNotSame('', $result->text);
    }

    public function test_unknown_provider_is_rejected_safely(): void
    {
        Http::fake();

        try {
            $this->gateway()->generate('Hello?', ['provider' => 'watson']);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_UNKNOWN_PROVIDER', $e->errorCode);
            $this->assertSame(503, $e->status);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usages', 0);
    }

    public function test_factory_resolves_new_providers(): void
    {
        $this->assertInstanceOf(GeminiProvider::class, AiProviderFactory::make('gemini'));
        $this->assertInstanceOf(AnthropicProvider::class, AiProviderFactory::make('anthropic'));
        $this->assertTrue(AiProviderFactory::isSupported('gemini'));
        $this->assertTrue(AiProviderFactory::isSupported('anthropic'));
        $this->assertFalse(AiProviderFactory::isSupported('watson'));
    }

    public function test_website_setting_overrides_default_provider(): void
    {
        WebsiteSetting::set('ai.default_provider', 'stub', 'ai');

        $result = $this->gateway()->generate('Hello?');

        $this->assertSame('stub', $result->provider);
    }

    /* C. Model resolution */

    public function test_explicit_model_wins_over_configured_default(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'explicit-model-9',
            'choices' => [['message' => ['content' => 'Hi.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ], 200)]);

        $result = $this->gateway()->generate('Hello?', [
            'provider' => 'openai',
            'model' => 'explicit-model-9',
        ]);

        Http::assertSent(fn ($request) => $request['model'] === 'explicit-model-9');
        $this->assertSame('explicit-model-9', $result->model);
    }

    public function test_provider_configured_model_is_used_by_default(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => 'Hi.'], 'finish_reason' => 'stop']],
        ], 200)]);

        $this->gateway()->generate('Hello?', ['provider' => 'openai']);

        Http::assertSent(fn ($request) => $request['model'] === config('ai.providers.openai.model'));
    }

    public function test_global_default_model_setting_applies_when_provider_has_none(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key', 'ai.providers.openai.model' => null]);
        WebsiteSetting::set('ai.default_model', 'fallback-model-1', 'ai');
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'fallback-model-1',
            'choices' => [['message' => ['content' => 'Hi.']]],
        ], 200)]);

        $this->gateway()->generate('Hello?', ['provider' => 'openai']);

        Http::assertSent(fn ($request) => $request['model'] === 'fallback-model-1');
    }

    /* D. Adapter normalization */

    public function test_openai_success_normalizes_usage_cost_and_latency(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'chatcmpl-abc',
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => '  Hello there. '], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500],
        ], 200)]);

        $student = User::factory()->create(['role' => 'student']);
        $result = $this->gateway()->generate('Hello?', ['provider' => 'openai', 'user' => $student]);

        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-4o-mini', $result->model);
        $this->assertSame('Hello there.', $result->text);
        $this->assertSame(1000, $result->inputTokens);
        $this->assertSame(500, $result->outputTokens);
        $this->assertSame(1500, $result->totalTokens);
        $this->assertSame('chatcmpl-abc', $result->requestId);
        $this->assertSame('stop', $result->finishReason);
        $this->assertNotNull($result->latencyMs);
        $this->assertNotNull($result->usageId);
        // 1000/1000*0.00015 + 500/1000*0.0006 = 0.000450
        $this->assertSame('0.000450', $result->estimatedCost);
        $this->assertSame('USD', $result->currency);

        $this->assertDatabaseHas('ai_usages', [
            'id' => $result->usageId,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'operation' => 'generate',
            'user_id' => $student->id,
            'request_id' => 'chatcmpl-abc',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'total_tokens' => 1500,
            'success' => true,
            'error_code' => null,
        ]);

        $usage = AiUsage::findOrFail($result->usageId);
        $this->assertNotNull($usage->request_hash);
        $this->assertNotNull($usage->latency_ms);
    }

    public function test_missing_token_usage_still_succeeds_without_cost(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => 'Hi.']]],
        ], 200)]);

        $result = $this->gateway()->generate('Hello?', ['provider' => 'openai']);

        $this->assertSame('Hi.', $result->text);
        $this->assertNull($result->inputTokens);
        $this->assertNull($result->outputTokens);
        $this->assertNull($result->estimatedCost);
        $this->assertDatabaseHas('ai_usages', ['id' => $result->usageId, 'success' => true]);
    }

    public function test_gemini_success_is_normalized(): void
    {
        config(['ai.providers.gemini.api_key' => 'gemini-test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Namaste.']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4, 'totalTokenCount' => 14],
        ], 200)]);

        $result = $this->gateway()->generate('Hello?', ['provider' => 'gemini']);

        $this->assertSame('gemini', $result->provider);
        $this->assertSame('Namaste.', $result->text);
        $this->assertSame(10, $result->inputTokens);
        $this->assertSame(4, $result->outputTokens);
        $this->assertSame(14, $result->totalTokens);
        $this->assertSame('STOP', $result->finishReason);
    }

    public function test_anthropic_success_is_normalized(): void
    {
        config(['ai.providers.anthropic.api_key' => 'anthropic-test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'model' => 'claude-3-5-haiku-latest',
            'content' => [['type' => 'text', 'text' => 'Greetings.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 6],
        ], 200)]);

        $result = $this->gateway()->generate('Hello?', ['provider' => 'anthropic']);

        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('Greetings.', $result->text);
        $this->assertSame(20, $result->inputTokens);
        $this->assertSame(6, $result->outputTokens);
        $this->assertSame('msg_1', $result->requestId);
        $this->assertSame('end_turn', $result->finishReason);
    }

    /* E. Provider failure */

    public function test_provider_timeout_is_normalized(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        try {
            $this->gateway()->generate('Hello?', ['provider' => 'openai']);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_PROVIDER_CONNECTION_ERROR', $e->errorCode);
            $this->assertSame(503, $e->status);
        }

        $this->assertDatabaseHas('ai_usages', [
            'provider' => 'openai',
            'success' => false,
            'error_code' => 'AI_PROVIDER_CONNECTION_ERROR',
        ]);
    }

    public function test_provider_http_error_is_normalized(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key', 'ai.providers.openai.model' => 'gpt-4o-mini']);
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'Rate limit reached.'],
        ], 429)]);

        try {
            $this->gateway()->generate('Hello?', ['provider' => 'openai']);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_PROVIDER_ERROR', $e->errorCode);
            $this->assertSame(422, $e->status);
            $this->assertSame('Rate limit reached.', $e->getMessage());
        }
    }

    public function test_malformed_provider_response_is_normalized(): void
    {
        config(['ai.providers.gemini.api_key' => 'gemini-test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['unexpected' => 'shape'], 200)]);

        try {
            $this->gateway()->generate('Hello?', ['provider' => 'gemini']);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_EMPTY_RESPONSE', $e->errorCode);
            $this->assertSame(502, $e->status);
        }
    }

    public function test_missing_api_key_is_a_controlled_configuration_error(): void
    {
        config(['ai.providers.anthropic.api_key' => null]);
        Http::fake();

        try {
            $this->gateway()->generate('Hello?', ['provider' => 'anthropic']);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_NOT_CONFIGURED', $e->errorCode);
            $this->assertSame(503, $e->status);
        }

        Http::assertNothingSent();
    }

    /* F. Usage logging */

    public function test_failed_request_records_failure_with_nullable_user(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response('Server exploded', 500)]);

        try {
            $this->gateway()->generate('Hello?', ['provider' => 'openai']);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('AI_PROVIDER_ERROR', $e->errorCode);
        }

        $this->assertDatabaseHas('ai_usages', [
            'provider' => 'openai',
            'user_id' => null,
            'success' => false,
            'error_code' => 'AI_PROVIDER_ERROR',
        ]);
    }

    public function test_usage_accepts_user_model_and_raw_id(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $byModel = $this->gateway()->generate('Hi?', ['provider' => 'stub', 'user' => $student]);
        $byId = $this->gateway()->generate('Hi?', ['provider' => 'stub', 'user' => $student->id]);
        $anonymous = $this->gateway()->generate('Hi?', ['provider' => 'stub']);

        $this->assertDatabaseHas('ai_usages', ['id' => $byModel->usageId, 'user_id' => $student->id]);
        $this->assertDatabaseHas('ai_usages', ['id' => $byId->usageId, 'user_id' => $student->id]);
        $this->assertDatabaseHas('ai_usages', ['id' => $anonymous->usageId, 'user_id' => null]);
    }

    public function test_chat_operation_is_recorded(): void
    {
        $result = $this->gateway()->chat(
            [['role' => 'user', 'content' => 'Explain variables.']],
            ['provider' => 'stub']
        );

        $this->assertDatabaseHas('ai_usages', [
            'id' => $result->usageId,
            'operation' => 'chat',
            'success' => true,
        ]);
    }

    /* G. Cost calculation */

    public function test_cost_estimate_for_known_model(): void
    {
        $calculator = app(AiCostCalculator::class);

        $estimate = $calculator->estimate('openai', 'gpt-4o-mini', 2000, 1000);

        // 2000/1000*0.00015 + 1000/1000*0.0006 = 0.000900
        $this->assertSame('0.000900', $estimate['cost']);
        $this->assertSame('USD', $estimate['currency']);
    }

    public function test_cost_estimate_unknown_model_returns_null(): void
    {
        $calculator = app(AiCostCalculator::class);

        $this->assertNull($calculator->estimate('openai', 'gpt-99-unknown', 2000, 1000));
        $this->assertNull($calculator->estimate('watson', 'anything', 2000, 1000));
        $this->assertNull($calculator->estimate(null, null, 2000, 1000));
    }

    public function test_cost_estimate_missing_tokens_returns_null(): void
    {
        $calculator = app(AiCostCalculator::class);

        $this->assertNull($calculator->estimate('openai', 'gpt-4o-mini', null, null));
    }

    public function test_cost_estimate_malformed_pricing_returns_null_without_throwing(): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input_per_1k' => 'n/a']]);
        $calculator = app(AiCostCalculator::class);

        $this->assertNull($calculator->estimate('openai', 'gpt-4o-mini', 2000, 1000));
    }

    /* H. Security */

    public function test_api_keys_never_appear_in_results_or_status_responses(): void
    {
        config([
            'ai.enabled' => true,
            'ai.providers.openai.api_key' => 'sk-test-openai-secret-1',
            'ai.providers.gemini.api_key' => 'gemini-test-secret-2',
            'ai.providers.anthropic.api_key' => 'anthropic-test-secret-3',
        ]);

        $result = $this->gateway()->generate('Hello?', ['provider' => 'stub']);
        $body = json_encode($result->toArray());

        foreach (['sk-test-openai-secret-1', 'gemini-test-secret-2', 'anthropic-test-secret-3'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ai/status');

        $response->assertOk();
        $statusBody = $response->getContent();

        foreach (['sk-test-openai-secret-1', 'gemini-test-secret-2', 'anthropic-test-secret-3'] as $secret) {
            $this->assertStringNotContainsString($secret, $statusBody);
        }
    }

    public function test_api_keys_never_appear_in_logs_on_failure(): void
    {
        // Collect every logged payload for this test only; the listener is
        // inert afterwards (it appends to a dead local array).
        $loggedPayloads = [];
        Log::listen(function ($event) use (&$loggedPayloads) {
            $loggedPayloads[] = json_encode([$event->level ?? null, $event->message ?? null, $event->context ?? null]);
        });

        config(['ai.providers.openai.api_key' => 'sk-test-secret-log-check']);
        Http::fake(['api.openai.com/*' => Http::response('Server exploded', 500)]);

        try {
            $this->gateway()->generate('Hello?', ['provider' => 'openai']);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertStringNotContainsString('sk-test-secret-log-check', $e->getMessage());
        }

        foreach ($loggedPayloads as $payload) {
            $this->assertStringNotContainsString('sk-test-secret-log-check', (string) $payload);
        }
    }

    public function test_admin_status_endpoint_requires_admin(): void
    {
        $this->getJson('/api/admin/ai/status')->assertUnauthorized();

        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/ai/status')
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/ai/status')
            ->assertOk()
            ->assertJsonStructure(['enabled', 'default_provider', 'default_model', 'providers']);
    }

    public function test_admin_status_reports_effective_config_without_secrets(): void
    {
        config([
            'ai.enabled' => true,
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-secret-status',
        ]);
        WebsiteSetting::set('ai.default_provider', 'stub', 'ai');

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ai/status');

        $response->assertOk()->assertJsonPath('default_provider', 'stub');
        $this->assertStringNotContainsString('sk-test-secret-status', $response->getContent());
    }
}
