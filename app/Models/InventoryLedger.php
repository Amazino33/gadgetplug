<?php

namespace App\Models;

use App\Casts\AsQuantity;
use Illuminate\Database\Eloquent\Model;

class InventoryLedger extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_change' => AsQuantity::class,
        'occurred_at'     => 'datetime',
    ];

    /**
     * Ledger types that record a reservation and leave the shelf quantity
     * alone. Everything else moves product_store_stock.quantity by exactly
     * its quantity_change.
     */
    public const RESERVATION_ONLY_TYPES = ['reserved', 'reservation_released'];

    protected static function booted(): void
    {
        // Every writer gets "now" unless it knows better — only a till sale,
        // which can sync hours after it was rung, does. Set here rather than
        // in each of the places that create ledger rows, so none can forget.
        static::creating(function (InventoryLedger $ledger) {
            $ledger->occurred_at ??= now();
        });

        // A movement that happened before it arrived (a till sale synced
        // late) can fall before an approved stock take count: correct it.
        static::created(function (InventoryLedger $ledger) {
            \App\Services\StockTake\LateSaleRecheck::noticed($ledger);
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    // Nullable until the stock mutators are made store-aware — see the
    // add_store_id_to_inventory_ledgers migration.
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditSession()
    {
        return $this->belongsTo(AuditSession::class);
    }
}
