<?php

namespace App\Observers;

use App\Models\Vendor;
use App\Services\VendorRoles;

class VendorObserver
{
    public function created(Vendor $vendor): void
    {
        VendorRoles::seedFor($vendor);
    }
}
