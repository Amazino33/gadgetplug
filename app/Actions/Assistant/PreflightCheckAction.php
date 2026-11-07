<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Exceptions\Assistant\AssistantCapExceeded;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\User;
use App\Services\Assistant\CostCalculator;
use App\Services\Assistant\TokenEstimator;

class PreflightCheckAction
{
    /**
     * Runs pre-flight checks before attempting any AI assistant work.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     *
     * @throws AssistantCapExceeded
     */
    public function execute(
        User $user,
        ?int $estimatedCostMicros = null,
        array $messages = [],
        array $tools = [],
    ): void {
        $settings = AssistantGlobalSetting::current();

        // 1. Global kill switch
        if (! $settings->is_enabled) {
            throw new AssistantCapExceeded('global_disabled', 'AI Assistant is currently disabled.');
        }

        $usage = AssistantDailyUsage::forToday($user);

        // 2. Message count cap check
        $messageCap = (int) ($settings->daily_message_cap ?? 0);
        if ($messageCap > 0 && $usage->message_count >= $messageCap) {
            throw new AssistantCapExceeded(
                'message_cap',
                "You've reached today's limit ({$messageCap} messages). Try again tomorrow."
            );
        }

        // 3. Current cost cap check
        $costCap = (int) ($settings->daily_cost_cap_micros ?? 0);
        if ($costCap > 0 && $usage->cost_micros >= $costCap) {
            throw new AssistantCapExceeded(
                'cost_cap',
                "You've reached today's assistant budget. Try again tomorrow."
            );
        }

        // 4. Pre-emptive cost estimate check
        if ($costCap > 0) {
            if ($estimatedCostMicros !== null) {
                $nextMessageCost = $estimatedCostMicros;
            } else {
                $modelName = (string) ($settings->model_name ?? 'openai/gpt-4o-mini');
                $maxTokens = (int) ($settings->max_tokens ?? 800);

                $inputTokens = (! empty($messages) || ! empty($tools))
                    ? TokenEstimator::estimateInput($messages, $tools)
                    : 1500;

                $nextMessageCost = CostCalculator::estimateCostMicros($modelName, $inputTokens, $maxTokens);
            }

            if (($usage->cost_micros + $nextMessageCost) >= $costCap) {
                throw new AssistantCapExceeded(
                    'cost_cap',
                    "You've reached today's assistant budget. Try again tomorrow."
                );
            }
        }
    }
}
