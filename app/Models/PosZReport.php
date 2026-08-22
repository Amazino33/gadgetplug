<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PosZReport extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'report_date'          => 'date',
        'cash_sales'           => 'decimal:2',
        'card_sales'           => 'decimal:2',
        'bank_transfer_sales'  => 'decimal:2',
        'total_sales'          => 'decimal:2',
        'total_vat'            => 'decimal:2',
        'total_discounts'      => 'decimal:2',
        'total_returns'        => 'decimal:2',
        'opening_float'        => 'decimal:2',
        'cash_expected'        => 'decimal:2',
        'cash_counted'         => 'decimal:2',
        'cash_variance'        => 'decimal:2',
        'generated_at'         => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['report_date', 'total_sales', 'cash_expected', 'cash_counted', 'cash_variance', 'generated_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => 'Z report ' . $event);
    }
}
