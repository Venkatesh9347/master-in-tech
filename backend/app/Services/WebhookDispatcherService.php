<?php

namespace App\Services;

use App\Jobs\SendWebhookDeliveryJob;
use App\Models\Certificate;
use App\Models\CourseEnrollment;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound webhook dispatcher (Phase 9).
 *
 * Supported events (allow-list):
 * - payment.paid
 * - payment.refunded
 * - enrollment.created
 * - certificate.issued
 *
 * Flow: dispatch() persists one immutable WebhookDelivery ledger row per
 * matching active subscription, then queues SendWebhookDeliveryJob with
 * afterCommit semantics — a rolled-back business transaction leaves neither
 * a ledger row nor a queued job. The job performs the signed HTTP POST and
 * owns retry bookkeeping; failures never propagate into business code.
 *
 * Signing scheme (documented, deterministic):
 * - signature = HMAC-SHA256(secret, timestamp . '.' . body)
 * - headers: X-Webhook-Event, X-Webhook-Timestamp (unix seconds),
 *   X-Webhook-Delivery (delivery id), X-Webhook-Signature (hex)
 * - receivers accept timestamps within SIGNATURE_TOLERANCE_SECONDS (300s).
 */
class WebhookDispatcherService
{
    public const EVENT_PAYMENT_PAID = 'payment.paid';
    public const EVENT_PAYMENT_REFUNDED = 'payment.refunded';
    public const EVENT_ENROLLMENT_CREATED = 'enrollment.created';
    public const EVENT_CERTIFICATE_ISSUED = 'certificate.issued';

    /**
     * @var list<string>
     */
    public const SUPPORTED_EVENTS = [
        self::EVENT_PAYMENT_PAID,
        self::EVENT_PAYMENT_REFUNDED,
        self::EVENT_ENROLLMENT_CREATED,
        self::EVENT_CERTIFICATE_ISSUED,
    ];

    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public const HEADER_EVENT = 'X-Webhook-Event';
    public const HEADER_TIMESTAMP = 'X-Webhook-Timestamp';
    public const HEADER_DELIVERY = 'X-Webhook-Delivery';
    public const HEADER_SIGNATURE = 'X-Webhook-Signature';

    /**
     * Outbound seam: certificate issued (call only after commit, only on
     * actual issuance). Payload carries internal ids only.
     */
    public static function dispatchOutboundCertificateIssued(Certificate $certificate): void
    {
        app(static::class)->dispatch(self::EVENT_CERTIFICATE_ISSUED, [
            'certificate_id' => (int) $certificate->id,
            'user_id' => (int) $certificate->user_id,
            'course_id' => (int) $certificate->course_id,
            'certificate_code' => (string) $certificate->certificate_code,
            'issued_at' => $certificate->issued_at?->toISOString(),
        ]);
    }

    /**
     * Outbound seam: enrollment created (fires from the model hook for every
     * creation path; safe inside transactions per dispatch() semantics).
     */
    public static function dispatchOutboundEnrollmentCreated(CourseEnrollment $enrollment): void
    {
        app(static::class)->dispatch(self::EVENT_ENROLLMENT_CREATED, [
            'enrollment_id' => (int) $enrollment->id,
            'user_id' => (int) $enrollment->user_id,
            'course_id' => (int) $enrollment->course_id,
            'status' => (string) $enrollment->status,
        ]);
    }

    /**
     * Fan out an event to every active matching subscription.
     *
     * Safe to call inside a business transaction: ledger rows participate in
     * it (rolled back on failure) and queued jobs run after commit.
     */
    public function dispatch(string $event, array $payload): void
    {
        if (! in_array($event, self::SUPPORTED_EVENTS, true)) {
            Log::warning('webhook.dispatch.unknown_event', ['event' => $event]);

            return;
        }

        $subscriptions = WebhookSubscription::where('is_active', true)->get();

        foreach ($subscriptions as $subscription) {
            if (! $subscription->wants($event)) {
                continue;
            }

            $delivery = WebhookDelivery::create([
                'webhook_subscription_id' => $subscription->id,
                'event' => $event,
                'payload' => $payload,
                'status' => WebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
            ]);

            SendWebhookDeliveryJob::dispatch($delivery->id)->afterCommit();
        }
    }

    /**
     * Perform a single delivery attempt synchronously. Returns true on
     * success. Never throws: failures are recorded on the ledger row.
     */
    public function attemptDelivery(WebhookDelivery $delivery): bool
    {
        $delivery = $delivery->fresh() ?? $delivery;
        $subscription = $delivery->subscription;

        if ($subscription === null || ! $subscription->is_active) {
            $this->recordFailure($delivery, 'Subscription missing or inactive.');

            return false;
        }

        if (! self::isAllowedTargetUrl((string) $subscription->target_url)) {
            $this->recordFailure($delivery, 'Target URL is not an allowed webhook destination.');

            return false;
        }

        $body = (string) json_encode($delivery->payload ?? []);
        $timestamp = (string) time();

        try {
            // S1: never follow redirects — a 302 to an internal destination
            // would otherwise bypass target validation. Redirects surface as
            // non-2xx and flow through the normal failure/retry path.
            // S1: never follow redirects — a 302 to an internal destination
            // would otherwise bypass target validation. Redirects surface as
            // non-2xx and flow through the normal failure/retry path.
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withHeaders([
                    self::HEADER_EVENT => $delivery->event,
                    self::HEADER_TIMESTAMP => $timestamp,
                    self::HEADER_DELIVERY => (string) $delivery->id,
                    self::HEADER_SIGNATURE => self::sign((string) $subscription->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post((string) $subscription->target_url);

            if (! $response->successful()) {
                $this->recordFailure($delivery, 'Unexpected HTTP status: ' . $response->status() . '.');

                return false;
            }
        } catch (\Throwable $e) {
            $this->recordFailure($delivery, 'Delivery error: ' . $e->getMessage());

            return false;
        }

        $delivery->update([
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'attempts' => (int) $delivery->attempts + 1,
            'delivered_at' => now(),
            'last_error' => null,
        ]);

        return true;
    }

    /**
     * Record a failed attempt with exponential backoff, or dead-letter the
     * delivery once MAX_ATTEMPTS is exhausted.
     */
    public function recordFailure(WebhookDelivery $delivery, string $error): void
    {
        $attempts = (int) $delivery->attempts + 1;

        if ($attempts >= WebhookDelivery::MAX_ATTEMPTS) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_DEAD,
                'attempts' => $attempts,
                'next_retry_at' => null,
                'last_error' => mb_substr($error, 0, 2000),
            ]);

            return;
        }

        $backoff = WebhookDelivery::RETRY_BACKOFF_SECONDS[$attempts - 1]
            ?? end(WebhookDelivery::RETRY_BACKOFF_SECONDS);

        $delivery->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'attempts' => $attempts,
            'next_retry_at' => now()->addSeconds((int) $backoff),
            'last_error' => mb_substr($error, 0, 2000),
        ]);
    }

    /**
     * Deterministic HMAC-SHA256 signature over timestamp + body.
     */
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Verify a signature with constant-time comparison and timestamp tolerance.
     */
    public static function verify(string $secret, string $timestamp, string $body, string $signature, ?int $now = null): bool
    {
        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (! is_numeric($timestamp)) {
            return false;
        }

        $now ??= time();

        if (abs($now - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }

    /**
     * Bounded SSRF guard for administrator-configured webhook targets.
     *
     * Allows http(s) URLs except obvious internal destinations: localhost,
     * loopback, private RFC1918 space, link-local (incl. cloud metadata
     * endpoints), and other reserved ranges. DNS-rebinding beyond literal
     * analysis is out of scope by design.
     */
    public static function isAllowedTargetUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        // parse_url() retains brackets on IPv6 literals ([::1]).
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        // Userinfo (credentials in URL) is never acceptable.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($host === 'localhost') {
            return false;
        }

        // Literal IP: reject loopback, private, link-local, and reserved.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (str_starts_with($host, '127.')) {
                return false;
            }

            if ($host === '::1') {
                return false;
            }

            if (str_starts_with(strtolower($host), 'fe80:')) {
                return false;
            }

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether plain HTTP targets are acceptable in the current environment.
     * Production-like environments require HTTPS.
     */
    public static function httpAllowed(): bool
    {
        return app()->environment(['local', 'testing', 'development']);
    }
}
