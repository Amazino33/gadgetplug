<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

class VendorActivity extends Activity
{
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
