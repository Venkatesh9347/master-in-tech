<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiProviderFactory;
use Illuminate\Http\JsonResponse;

/**
 * AI-0 foundation administration (read-only status).
 *
 * Reports the effective AI configuration (kill switch, default provider,
 * default model, per-provider readiness) so administrators can verify
 * setup. Never exposes API keys or any secret values. Non-secret toggles
 * (`ai.enabled`, `ai.default_provider`, `ai.default_model`) are managed
 * through the existing website-settings endpoint (`PUT /admin/settings`,
 * group "ai"), which already audits changes — no second settings system.
 */
class AiAdminController extends Controller
{
    public function status(AiGatewayService $gateway): JsonResponse
    {
        $defaultProvider = $gateway->defaultProvider();

        $providers = [];

        foreach (AiProviderFactory::SUPPORTED_PROVIDERS as $name) {
            $providers[$name] = [
                'configured' => $gateway->isProviderConfigured($name),
                'model' => $gateway->defaultModelFor($name),
            ];
        }

        return response()->json([
            'enabled' => $gateway->isEnabled(),
            'default_provider' => $defaultProvider,
            'default_model' => $gateway->defaultModelFor($defaultProvider),
            'providers' => $providers,
        ]);
    }
}
