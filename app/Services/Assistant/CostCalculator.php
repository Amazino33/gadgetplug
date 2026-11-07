<?php

declare(strict_types=1);

namespace App\Services\Assistant;

class CostCalculator
{
    /**
     * Computes actual cost in micro-dollars ($1 = 1,000,000 micros) from token counts.
     */
    public static function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        if ($inputTokens <= 0 && $outputTokens <= 0) {
            return 0;
        }

        $rates = config('assistant.rates', []);
        $modelRates = $rates[$model] ?? ($rates['default'] ?? [
            'input_micros_per_token' => 0.50,
            'output_micros_per_token' => 1.50,
        ]);

        $inputRate = (float) ($modelRates['input_micros_per_token'] ?? 0.50);
        $outputRate = (float) ($modelRates['output_micros_per_token'] ?? 1.50);

        $inputCost = max(0, $inputTokens) * $inputRate;
        $outputCost = max(0, $outputTokens) * $outputRate;

        return (int) round($inputCost + $outputCost);
    }

    /**
     * Estimates cost in micro-dollars before invoking the AI.
     */
    public static function estimateCostMicros(string $model, int $estimatedInputTokens, int $maxOutputTokens): int
    {
        return self::costMicros($model, $estimatedInputTokens, $maxOutputTokens);
    }
}
