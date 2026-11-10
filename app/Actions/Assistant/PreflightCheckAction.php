<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Exceptions\Assistant\AssistantCapExceeded;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantVendorDailyUsage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\CostCalculator;
use App\Services\Assistant\TokenEstimator;
use Carbon\CarbonImmutable;

class PreflightCheckAction
{
    /**
     * Runs pre-flight checks before attempting any AI assistant work.
     *
     * Every cap counts the Lagos trading day (BusinessDate), so all of them
     * reset at Lagos midnight, and the message says how long that is.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  Vendor|null  $vendor  the business asking; its daily question cap applies
     *
     * @throws AssistantCapExceeded
     */
    public function execute(
        User $user,
        ?int $estimatedCostMicros = null,
        array $messages = [],
        array $tools = [],
        ?Vendor $vendor = null,
    ): void {
        $settings = AssistantGlobalSetting::current();

        // 1. Global kill switch
        if (! $settings->is_enabled) {
            throw new AssistantCapExceeded('global_disabled', 'AI Assistant is currently disabled.');
        }

        // 2. The business's questions for the day (locked decision: 100 per
        //    business per Lagos day, adjustable per business by the admin).
        if ($vendor !== null) {
            $vendorCap = self::vendorCap($vendor, $settings);

            if ($vendorCap > 0 && AssistantVendorDailyUsage::questionsToday((int) $vendor->id) >= $vendorCap) {
                throw new AssistantCapExceeded(
                    'vendor_cap',
                    "Your business has used all {$vendorCap} of today's questions. They reset at midnight Lagos time, ".self::untilReset().'.'
                );
            }
        }

        $usage = AssistantDailyUsage::forToday($user);

        // 3. Message count cap check (per user, a backstop)
        $messageCap = (int) ($settings->daily_message_cap ?? 0);
        if ($messageCap > 0 && $usage->message_count >= $messageCap) {
            throw new AssistantCapExceeded(
                'message_cap',
                "You've reached today's limit ({$messageCap} messages). It resets at midnight Lagos time, ".self::untilReset().'.'
            );
        }

        // 4. Current cost cap check
        $costCap = (int) ($settings->daily_cost_cap_micros ?? 0);
        if ($costCap > 0 && $usage->cost_micros >= $costCap) {
            throw new AssistantCapExceeded('cost_cap', self::budgetMessage());
        }

        // 5. Pre-emptive cost estimate check
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
                throw new AssistantCapExceeded('cost_cap', self::budgetMessage());
            }
        }
    }

    /** The business's questions per day: its own override, else the platform default. 0 = no limit. */
    public static function vendorCap(Vendor $vendor, ?AssistantGlobalSetting $settings = null): int
    {
        $settings ??= AssistantGlobalSetting::current();

        return (int) ($vendor->assistant_daily_cap ?? $settings->daily_vendor_cap ?? 100);
    }

    /** "in about 5 hours": the time left until Lagos midnight, when every cap resets. */
    public static function untilReset(): string
    {
        $tz = (string) config('reporting.timezone', 'Africa/Lagos');
        $now = CarbonImmutable::now($tz);
        $minutes = (int) ceil($now->diffInSeconds($now->addDay()->startOfDay()) / 60);

        return match (true) {
            $minutes < 2 => 'in a minute',
            $minutes < 55 => "in about {$minutes} minutes",
            $minutes < 90 => 'in about an hour',
            default => 'in about '.(int) round($minutes / 60).' hours',
        };
    }

    private static function budgetMessage(): string
    {
        return "You've reached today's assistant budget. It resets at midnight Lagos time, ".self::untilReset().'.';
    }
}
