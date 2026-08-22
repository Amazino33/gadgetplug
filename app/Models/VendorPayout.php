<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class VendorPayout extends Model
{
    use LogsActivity;

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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'status', 'bank_name', 'account_number', 'account_name', 'admin_notes', 'settled_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => 'Payout ' . $event);
    }
}
