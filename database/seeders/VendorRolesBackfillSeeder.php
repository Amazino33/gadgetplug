<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\VendorRoles;
use App\Models\Vendor;

class VendorRolesBackfillSeeder extends Seeder
{
    public function run(): void
    {
        Vendor::all()->each(fn (Vendor $vendor) => VendorRoles::seedFor($vendor));
    }
}
