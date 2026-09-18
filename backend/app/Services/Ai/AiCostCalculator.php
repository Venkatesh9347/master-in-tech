<?php

namespace App\Services\Ai;

/**
 * Estimates AI usage cost from configuration pricing only.
 *
 * Pricing lives in `config('ai.pricing')` as per-1K-token input/output
 * rates — never hard-coded in adapters. Unknown models, missing token
 * usage, or malformed pricing entries yield null (no estimate) and never
 * throw, so cost tracking can never fail an AI request. Configured prices
 * are placeholders: verify current provider pricing before relying on
 * estimates for anything beyond rough internal accounting.
 */
class AiCostCalculator
{
    /**
     * @return array{cost: string, currency: string}|null
     */
    public function estimate(?string $provider, ?string $model, mixed $inputTokens, mixed $outputTokens): ?array
    {
        if (! is_string($provider) || $provider === '' || ! is_string($model) || $model === '') {
            return null;
        }

        $entry = config("ai.pricing.{$provider}.{$model}");

        if (! is_array($entry)) {
            return null;
        }

        $inputRate = $entry['input_per_1k'] ?? null;
        $outputRate = $entry['output_per_1k'] ?? null;
        $currency = $entry['currency'] ?? 'USD';

        if (! is_numeric($inputRate) || ! is_numeric($outputRate)) {
            return null;
        }

        if (! is_numeric($inputTokens) && ! is_numeric($outputTokens)) {
            return null;
        }

        $cost = ((float) ($inputTokens ?? 0) / 1000) * (float) $inputRate
            + ((float) ($outputTokens ?? 0) / 1000) * (float) $outputRate;

        return [
            'cost' => number_format($cost, 6, '.', ''),
            'currency' => is_string($currency) && $currency !== '' ? $currency : 'USD',
        ];
    }
}
