<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    protected $fillable = [
        'vendor_id', 'name', 'type', 'opening_balance', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active'       => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class, 'financial_account_id');
    }

    // Derived, never stored — same discipline as WalletService::availableBalance().
    // $asOf lets a caller ask "what was the balance on this date" by excluding
    // later movements, rather than only ever answering "right now".
    public function balance(?CarbonInterface $asOf = null): float
    {
        $entries = $this->ledgerEntries()
            ->when($asOf, fn ($query) => $query->where('occurred_at', '<=', $asOf->toDateString()));

        $in  = (clone $entries)->where('direction', 'in')->sum('amount');
        $out = (clone $entries)->where('direction', 'out')->sum('amount');

        return (float) $this->opening_balance + (float) $in - (float) $out;
    }
}
