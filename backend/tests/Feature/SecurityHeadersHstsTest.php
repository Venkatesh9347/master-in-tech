<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * B17 — HSTS emission contract.
 *
 * TLS terminates at the edge (nginx / ALB / CloudFront) per DEPLOYMENT.md §22,
 * so the header is emitted by the application's SecurityHeaders middleware when
 * either the request is genuinely HTTPS or SECURITY_HSTS_FORCE is set for a
 * TLS-terminating proxy.
 *
 * These tests verify the production *mechanism* locally. They deliberately do
 * not assert that a real HTTPS deployment exists — that can only be proven
 * against the live endpoint and is tracked separately.
 */
class SecurityHeadersHstsTest extends TestCase
{
    private function headerFor(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        return array_keys($response->headers->all());
    }

    public function test_plain_http_local_does_not_advertise_hsts(): void
    {
        // Guard against "fixing" the finding by emitting HSTS over plain HTTP,
        // which would pin local development to a non-existent HTTPS origin.
        config(['security.hsts_force' => false]);

        $headers = $this->headerFor('/api/public/navigation');

        $this->assertNotContains('strict-transport-security', $headers);
    }

    public function test_baseline_security_headers_are_always_present(): void
    {
        $headers = $this->headerFor('/api/public/navigation');

        $this->assertContains('x-content-type-options', $headers);
        $this->assertContains('x-frame-options', $headers);
        $this->assertContains('referrer-policy', $headers);
    }

    public function test_hsts_is_emitted_when_forced_for_a_tls_terminating_proxy(): void
    {
        // This is the production path: nginx terminates TLS and forwards HTTP,
        // so request()->secure() is false and the flag is what enables HSTS.
        config(['security.hsts_force' => true]);

        $response = $this->get('/api/public/navigation');
        $response->assertOk();

        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains'
        );
    }

    public function test_hsts_is_emitted_on_a_genuinely_https_request(): void
    {
        config(['security.hsts_force' => false]);

        // An absolute https:// URI makes Symfony resolve the scheme directly, so
        // request()->secure() is true without any proxy involvement — i.e. the
        // header appears on a real HTTPS connection with no forcing flag.
        $response = $this->get('https://localhost/api/public/navigation');

        $response->assertOk();
        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains'
        );
    }

    public function test_hsts_flag_defaults_to_disabled(): void
    {
        // Guards the safe default: nothing turns HSTS on implicitly.
        $this->assertFalse((bool) config('security.hsts_force'));
    }
}