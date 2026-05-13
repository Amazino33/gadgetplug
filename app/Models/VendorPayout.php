<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPayout extends Model
{
    protected $fillable = [
        'vendor_id', 'amount', 'bank_name', 'account_number',
        'account_name', 'status', 'admin_notes', 'settled_at',
    ];

    protected $casts = [
        'settled_at' => 'datetime',
        'amount'     => 'float',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
