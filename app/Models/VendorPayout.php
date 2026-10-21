<?php

namespace App\Models;

use App\Services\FinancialLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class VendorPayout extends Model
{
    use LogsActivity;

    /** Requested under the old "earned from paid and delivered orders" figure. Left as they are. */
    public const FUNDED_LEGACY = 'legacy';

    /** Requested from the Due from GadgetPlug balance: only money GadgetPlug has actually collected. */
    public const FUNDED_GADGETPLUG_DUE = 'gadgetplug_due';

    public const MINIMUM = 500;

    protected $fillable = [
        'vendor_id', 'amount', 'bank_name', 'account_number',
        'account_name', 'status', 'admin_notes', 'settled_at', 'funded_from',
    ];

    protected $casts = [
        'settled_at' => 'datetime',
        'amount'     => 'float',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * What a vendor can withdraw: their Due from GadgetPlug balance (online
     * money GadgetPlug has collected for them, less what has been paid out)
     * minus requests already waiting on it. Requests made under the old rule
     * are not counted here — they were drawn on different money and are left
     * for an admin to settle as they are.
     *
     * @return array{collected: float, committed: float, available: float}
     */
    public static function balanceFor(int $vendorId): array
    {
        $due = FinancialAccount::where('vendor_id', $vendorId)
            ->where('type', FinancialAccount::TYPE_GADGETPLUG_DUE)
            ->first();

        $collected = $due ? $due->balance() : 0.0;

        $committed = (float) static::where('vendor_id', $vendorId)
            ->where('funded_from', self::FUNDED_GADGETPLUG_DUE)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount');

        return [
            'collected' => round($collected, 2),
            'committed' => round($committed, 2),
            'available' => round(max(0, $collected - $committed), 2),
        ];
    }

    /**
     * Settle the payout. One drawn on Due from GadgetPlug moves the money in
     * the vendor's books too: out of Due, into their bank account (the first
     * active one; with none, only the Due side posts and it is logged). A
     * legacy request just changes status, as before.
     */
    public function markPaid(string $note, ?int $userId = null): void
    {
        DB::transaction(function () use ($note, $userId) {
            $this->update([
                'status'      => 'paid',
                'admin_notes' => $note,
                'settled_at'  => now(),
            ]);

            if ($this->funded_from !== self::FUNDED_GADGETPLUG_DUE) {
                return;
            }

            FinancialLedger::postEntry(
                account: FinancialAccount::gadgetplugDueFor($this->vendor_id),
                direction: 'out',
                amount: (float) $this->amount,
                source: $this,
                description: "Payout #{$this->id} to {$this->bank_name}",
                createdBy: $userId,
            );

            $bank = FinancialAccount::where('vendor_id', $this->vendor_id)
                ->where('type', FinancialAccount::TYPE_BANK)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if (! $bank) {
                Log::warning("Payout {$this->id}: vendor {$this->vendor_id} has no bank account to receive it in their books.");

                return;
            }

            FinancialLedger::postEntry(
                account: $bank,
                direction: 'in',
                amount: (float) $this->amount,
                source: $this,
                description: "Payout #{$this->id} from GadgetPlug",
                createdBy: $userId,
            );
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'funded_from', 'status', 'bank_name', 'account_number', 'account_name', 'admin_notes', 'settled_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => 'Payout ' . $event);
    }
}
