<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhookDeliveryJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWebhookController extends Controller
{
    /**
     * Paginated subscription registry (read-only).
     *
     * Safe projection only: secrets are never serialized (model $hidden
     * plus explicit payload below).
     */
    public function indexSubscriptions(Request $request): JsonResponse
    {
        $subscriptions = WebhookSubscription::query()
            ->withCount(['deliveries as failed_deliveries' => fn ($q) => $q->whereIn('status', [
                WebhookDelivery::STATUS_FAILED,
                WebhookDelivery::STATUS_DEAD,
            ])])
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request));

        $subscriptions->getCollection()->transform(fn (WebhookSubscription $s) => $this->subscriptionPayload($s));

        return response()->json($subscriptions);
    }

    /**
     * Register a new outbound webhook subscription.
     */
    public function storeSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate($this->subscriptionRules());

        $subscription = WebhookSubscription::create([
            'target_url' => $validated['target_url'],
            'secret' => $validated['secret'],
            'events' => array_values(array_unique($validated['events'])),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['subscription' => $this->subscriptionPayload($subscription)], 201);
    }

    /**
     * Single subscription detail (read-only, no secret).
     */
    public function showSubscription(WebhookSubscription $subscription): JsonResponse
    {
        return response()->json(['subscription' => $this->subscriptionPayload($subscription)]);
    }

    /**
     * Update subscription target/events/active flag (secret rotation optional).
     */
    public function updateSubscription(Request $request, WebhookSubscription $subscription): JsonResponse
    {
        $validated = $request->validate($this->subscriptionRules(true));

        $subscription->fill([
            'target_url' => $validated['target_url'] ?? $subscription->target_url,
            'events' => isset($validated['events']) ? array_values(array_unique($validated['events'])) : $subscription->events,
            'is_active' => $validated['is_active'] ?? $subscription->is_active,
        ]);

        if (isset($validated['secret'])) {
            $subscription->secret = $validated['secret'];
        }

        $subscription->save();

        return response()->json(['subscription' => $this->subscriptionPayload($subscription->fresh() ?? $subscription)]);
    }

    /**
     * Delete a subscription (deliveries cascade).
     */
    public function destroySubscription(WebhookSubscription $subscription): JsonResponse
    {
        $subscription->delete();

        return response()->json(['message' => 'Subscription deleted.']);
    }

    /**
     * Paginated delivery ledger (read-only, newest first). Deliveries carry
     * no secrets.
     */
    public function indexDeliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'event' => ['nullable', 'string', 'max:128'],
            'webhook_subscription_id' => ['nullable', 'integer'],
        ]);

        $deliveries = WebhookDelivery::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($validated['webhook_subscription_id'] ?? null, fn ($q, $id) => $q->where('webhook_subscription_id', $id))
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request));

        $deliveries->getCollection()->transform(fn (WebhookDelivery $d) => $this->deliveryPayload($d));

        return response()->json($deliveries);
    }

    /**
     * Manually retry a failed/dead delivery. Resets the attempt cycle
     * without duplicating subscriptions or ledger rows; delivered (or
     * already pending) rows are rejected so a success is never resent.
     */
    public function retryDelivery(WebhookDelivery $delivery): JsonResponse
    {
        if (! $delivery->isRetryable()) {
            return response()->json([
                'message' => 'Only failed or dead deliveries can be retried.',
            ], 422);
        }

        $delivery->update([
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'next_retry_at' => null,
            'delivered_at' => null,
            'last_error' => null,
        ]);

        SendWebhookDeliveryJob::dispatch($delivery->id);

        return response()->json(['delivery' => $this->deliveryPayload($delivery->fresh() ?? $delivery)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionRules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            'target_url' => [
                $required,
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                        $fail('The target URL must be a valid URL.');

                        return;
                    }

                    if (! WebhookDispatcherService::httpAllowed()
                        && strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
                        $fail('The target URL must use HTTPS outside local environments.');

                        return;
                    }

                    if (! WebhookDispatcherService::isAllowedTargetUrl($value)) {
                        $fail('The target URL must not point to an internal destination.');
                    }
                },
            ],
            'secret' => [$required, 'string', 'min:32', 'max:255'],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', WebhookDispatcherService::SUPPORTED_EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(WebhookSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'target_url' => $subscription->target_url,
            'events' => $subscription->events ?? [],
            'is_active' => (bool) $subscription->is_active,
            'failed_deliveries' => (int) ($subscription->getAttribute('failed_deliveries') ?? 0),
            'created_at' => $subscription->created_at?->toISOString(),
            'updated_at' => $subscription->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryPayload(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'webhook_subscription_id' => $delivery->webhook_subscription_id,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => (int) $delivery->attempts,
            'next_retry_at' => $delivery->next_retry_at?->toISOString(),
            'delivered_at' => $delivery->delivered_at?->toISOString(),
            'last_error' => $delivery->last_error,
            'created_at' => $delivery->created_at?->toISOString(),
        ];
    }
}
