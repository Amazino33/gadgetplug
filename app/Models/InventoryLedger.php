<?php

namespace App\Models;

use App\Casts\AsQuantity;
use Illuminate\Database\Eloquent\Model;

class InventoryLedger extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_change' => AsQuantity::class,
    ];

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
