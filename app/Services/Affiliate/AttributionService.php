<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Order;
use Illuminate\Support\Facades\Cookie;

class AttributionService
{
    public const COOKIE_NAME = 'gp_ref';

    public function __construct(private CommissionService $commissionService) {}

    /**
     * Attribution + commission creation for a freshly-created order. Call
     * this once the order's items exist (rate resolution needs them).
     *
     * Resolution order: a code entered at checkout beats the cookie — if a
     * code is entered but doesn't match a real, active affiliate, it's
     * treated as if nothing was entered (falls through to the cookie) rather
     * than blocking attribution outright, since a typo shouldn't forfeit a
     * legitimate click the customer already carries.
     */
    public function attributeOrder(Order $order, ?string $enteredCode, ?string $cookieCode): ?AffiliateCommission
    {
        $affiliate = $this->resolveAffiliate($enteredCode, $cookieCode);

        if (! $affiliate) {
            return null;
        }

        if ($this->isSelfReferral($affiliate, $order)) {
            return null;
        }

        return $this->commissionService->createForOrder($order, $affiliate);
    }

    public function resolveAffiliate(?string $enteredCode, ?string $cookieCode): ?Affiliate
    {
        if (filled($enteredCode)) {
            $affiliate = $this->findActiveByCode($enteredCode);

            if ($affiliate) {
                return $affiliate;
            }
        }

        if (filled($cookieCode)) {
            return $this->findActiveByCode($cookieCode);
        }

        return null;
    }

    public function findActiveByCode(string $code): ?Affiliate
    {
        return Affiliate::where('code', $code)->where('is_active', true)->first();
    }

    public function isSelfReferral(Affiliate $affiliate, Order $order): bool
    {
        if ($order->user_id && $affiliate->user_id === $order->user_id) {
            return true;
        }

        return $affiliate->user
            && strcasecmp($affiliate->user->email, $order->customer_email) === 0;
    }

    /**
     * Sets the attribution cookie for `cookie_window_days` (default 30),
     * matching the last-click model — a later click overwrites this one.
     */
    public function rememberClick(Affiliate $affiliate): void
    {
        $days = (int) \App\Models\AffiliateSetting::current()->cookie_window_days;

        Cookie::queue(self::COOKIE_NAME, $affiliate->code, $days * 24 * 60);
    }
}
