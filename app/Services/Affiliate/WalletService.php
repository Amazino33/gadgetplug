<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateCommission;
use App\Models\WalletTransaction;

// Balances are always derived, never stored — same discipline this codebase
// already applies to live VAT (never cached) and Product's profit/margin
// accessors (always computed from cost_price, never persisted).
class WalletService
{
    /**
     * Sum of commissions still held (not yet earned, not yet lost).
     */
    public function pendingBalance(int $affiliateId): float
    {
        return (float) AffiliateCommission::where('affiliate_id', $affiliateId)
            ->whereIn('status', ['pending', 'return_window'])
            ->sum('amount');
    }

    /**
     * Sum of the wallet ledger — the only balance that's actually
     * withdrawable/spendable.
     */
    public function availableBalance(int $affiliateId): float
    {
        return (float) WalletTransaction::where('affiliate_id', $affiliateId)->sum('amount');
    }
}
