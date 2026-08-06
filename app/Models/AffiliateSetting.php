<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Single row (id=1 always). No DB-backed, Filament-editable settings
// mechanism exists elsewhere in this app to reuse — this is a new, minimal
// pattern rather than an existing one being extended (see Phase 0 recon).
class AffiliateSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'platform_default_rate'              => 'decimal:2',
        'min_payout_amount'                  => 'decimal:2',
        'margin_cap_fraction'                => 'decimal:2',
        'platform_default_reseller_discount' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::findOrFail(1);
    }
}
