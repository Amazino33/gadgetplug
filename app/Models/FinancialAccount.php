<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    public const TYPE_BANK = 'bank';

    public const TYPE_CASH = 'cash';

    /**
     * "Due from GadgetPlug": money GadgetPlug collected for this vendor's
     * online orders (Paystack, or pay-on-delivery cash its riders took) and
     * still owes them. Online revenue posts here — never to the vendor's own
     * cash or bank, which never held it — and a payout moves it to their bank.
     * Not something to spend from or pay into by hand.
     */
    public const TYPE_GADGETPLUG_DUE = 'gadgetplug_due';

    public const TYPES = [self::TYPE_BANK, self::TYPE_CASH, self::TYPE_GADGETPLUG_DUE];

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

    /**
     * Accounts a person may pick to pay from or into: the vendor's own cash and
     * bank. Every "paid from" / "received into" picker uses this, so the Due
     * from GadgetPlug balance can only move through revenue and payouts.
     */
    public function scopeSpendable($query)
    {
        return $query->whereIn('type', [self::TYPE_BANK, self::TYPE_CASH]);
    }

    /** The vendor's Due from GadgetPlug account, created the first time it is needed. */
    public static function gadgetplugDueFor(int $vendorId): self
    {
        return static::firstOrCreate(
            ['vendor_id' => $vendorId, 'type' => self::TYPE_GADGETPLUG_DUE],
            ['name' => 'Due from GadgetPlug', 'opening_balance' => 0],
        );
    }

    public function isGadgetplugDue(): bool
    {
        return $this->type === self::TYPE_GADGETPLUG_DUE;
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
