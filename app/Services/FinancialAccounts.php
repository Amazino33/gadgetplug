<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\Vendor;

class FinancialAccounts
{
    /**
     * Every vendor gets exactly one bank and one cash account, both starting
     * at zero — the owner sets real opening balances afterwards. A table
     * rather than a hardcoded pair so a store could add another drawer in
     * v2, but idempotent per (vendor, type) so re-running this never
     * duplicates the two seeded accounts.
     */
    public static function seedFor(Vendor $vendor): void
    {
        FinancialAccount::firstOrCreate(
            ['vendor_id' => $vendor->id, 'type' => 'bank'],
            ['name' => 'Bank Account', 'opening_balance' => 0],
        );

        FinancialAccount::firstOrCreate(
            ['vendor_id' => $vendor->id, 'type' => 'cash'],
            ['name' => 'Cash Account', 'opening_balance' => 0],
        );
    }
}
