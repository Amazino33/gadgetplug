<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantGlobalSetting;
use App\Models\AssistantSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;

class AssistantAccess
{
    /**
     * Check if the assistant is enabled for this user.
     * Pure read operation — never writes to DB or triggers race conditions under concurrency.
     */
    public static function isEnabledFor(?User $user, ?Vendor $vendor = null): bool
    {
        if (! $user) {
            return false;
        }

        // Inside a business (the chat bubble's panel tenant), the assistant is
        // a permission like any other: owner-only by default, grantable to a
        // role. Without a vendor the old switches alone decide, and data is
        // then limited to stores the user owns (see AssistantScope).
        if ($vendor && ! AssistantScope::for($user, $vendor)->can('use_ai_assistant')) {
            return false;
        }

        $globalSetting = AssistantGlobalSetting::current();
        if (! $globalSetting->is_enabled) {
            return false;
        }

        $settings = AssistantSetting::query()
            ->where('user_id', $user->id)
            ->first();

        // If no settings row exists yet, treat as enabled-by-default
        if (! $settings) {
            return true;
        }

        return ! $settings->admin_disabled && ! $settings->vendor_disabled;
    }

    /**
     * Lazily get or create the settings row for write operations safely.
     */
    public static function getOrCreateFor(User $user): AssistantSetting
    {
        $setting = AssistantSetting::query()->where('user_id', $user->id)->first();

        if ($setting) {
            return $setting;
        }

        try {
            return AssistantSetting::query()->create([
                'user_id' => $user->id,
                'admin_disabled' => false,
                'vendor_disabled' => false,
            ]);
        } catch (QueryException $e) {
            // In case of a concurrent race condition on insert, return the newly created row
            return AssistantSetting::query()->where('user_id', $user->id)->firstOrFail();
        }
    }
}
