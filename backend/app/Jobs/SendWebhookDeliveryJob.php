<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * C1: the webhook ledger owns a five-execution policy (1 initial + 4
     * retries, then dead-letter). This property travels in the job payload
     * and takes precedence over the worker's global --tries flag, so a
     * --tries=3 worker cannot truncate webhook retries.
     */
    public $tries = 5;

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(WebhookDispatcherService $dispatcher): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        // A terminal delivery (already delivered/dead, e.g. via manual
        // retry racing this job) must never send twice.
        if ($delivery->isTerminal()) {
            return;
        }

        $ok = $dispatcher->attemptDelivery($delivery);

        if ($ok) {
            return;
        }

        $fresh = $delivery->fresh() ?? $delivery;

        if ($fresh->isTerminal() || $fresh->next_retry_at === null) {
            return;
        }

        $delay = max(0, $fresh->next_retry_at->getTimestamp() - time());

        $this->release($delay);
    }
}
