<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One row per change to a free shop's review status. Written only by
// App\Services\FreePlan\FreeShopApplications, so every transition is logged
// the same way whichever screen made it.
class VendorStatusLog extends Model
{
    protected $guarded = [];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
