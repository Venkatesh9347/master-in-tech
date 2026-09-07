<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    private function runThroughMiddleware(Request $request): Request
    {
        $proxies = new TrustProxies();
        $proxies->handle($request, fn () => new Response());

        return $request;
    }

    public function test_forwarded_headers_are_ignored_with_no_trusted_proxies(): void
    {
        config()->set('trustedproxy.proxies', null);

        $request = $this->runThroughMiddleware(Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
        ]));

        $this->assertFalse($request->isSecure());
        $this->assertSame('203.0.113.10', $request->getClientIp());
    }

    public function test_forwarded_proto_is_honored_for_trusted_proxy_ip(): void
    {
        config()->set('trustedproxy.proxies', '10.0.0.5');

        $request = $this->runThroughMiddleware(Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        $this->assertTrue($request->isSecure());
    }

    public function test_forwarded_headers_from_untrusted_ip_are_ignored(): void
    {
        config()->set('trustedproxy.proxies', '10.0.0.5');

        $request = $this->runThroughMiddleware(Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        $this->assertFalse($request->isSecure());
    }

    public function test_wildcard_is_only_trusted_when_explicitly_configured(): void
    {
        config()->set('trustedproxy.proxies', '*');

        $request = $this->runThroughMiddleware(Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.30',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        $this->assertTrue($request->isSecure());
    }
}