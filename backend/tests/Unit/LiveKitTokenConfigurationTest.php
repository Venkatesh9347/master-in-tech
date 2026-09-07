<?php

namespace Tests\Unit;

use App\Services\LiveKit\Exceptions\LiveKitConfigurationException;
use App\Services\LiveKit\LiveKitTokenService;
use Tests\TestCase;

class LiveKitTokenConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['services.livekit.url', 'services.livekit.api_key', 'services.livekit.api_secret'] as $key) {
            config([$key => null]);
        }

        parent::tearDown();
    }

    public function test_it_reports_not_configured_when_credentials_are_missing(): void
    {
        config([
            'services.livekit.url' => '',
            'services.livekit.api_key' => '',
            'services.livekit.api_secret' => '',
        ]);

        $this->assertFalse(LiveKitTokenService::isConfigured());
    }

    public function test_it_reports_configured_when_credentials_are_present(): void
    {
        config([
            'services.livekit.url' => 'wss://livekit.dev.test',
            'services.livekit.api_key' => 'devkey',
            'services.livekit.api_secret' => 'secret',
        ]);

        $this->assertTrue(LiveKitTokenService::isConfigured());
    }

    public function test_it_fails_loud_when_generating_token_without_credentials(): void
    {
        config([
            'services.livekit.url' => '',
            'services.livekit.api_key' => '',
            'services.livekit.api_secret' => '',
        ]);

        $service = new LiveKitTokenService();

        $this->expectException(LiveKitConfigurationException::class);

        // createTokenForSession requires model instances; assert early-guard path
        // provokes the configuration exception before any model access.
        $service->getWsUrl();
    }

    public function test_get_ws_url_fails_loud_without_configuration(): void
    {
        config([
            'services.livekit.url' => '',
            'services.livekit.api_key' => 'devkey',
            'services.livekit.api_secret' => 'secret',
        ]);

        $service = new LiveKitTokenService();

        $this->expectException(LiveKitConfigurationException::class);
        $service->getWsUrl();
    }

    public function test_get_api_key_fails_loud_without_configuration(): void
    {
        config([
            'services.livekit.url' => 'wss://livekit.dev.test',
            'services.livekit.api_key' => '',
            'services.livekit.api_secret' => 'secret',
        ]);

        $service = new LiveKitTokenService();

        $this->expectException(LiveKitConfigurationException::class);
        $service->getApiKey();
    }

    public function test_token_is_not_minted_with_empty_credentials(): void
    {
        config([
            'services.livekit.url' => '',
            'services.livekit.api_key' => '',
            'services.livekit.api_secret' => '',
        ]);

        $service = new LiveKitTokenService();
        $user = \Mockery::mock(\App\Models\User::class);
        $session = \Mockery::mock(\App\Models\LiveClassroomSession::class);

        try {
            $service->createTokenForSession($session, $user);
            $this->fail('Expected LiveKitConfigurationException was not thrown.');
        } catch (LiveKitConfigurationException $e) {
            $this->assertStringContainsString('LiveKit is not configured', $e->getMessage());
        }
    }
}
