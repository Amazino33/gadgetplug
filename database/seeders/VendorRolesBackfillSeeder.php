<?php

namespace Database\Seeders;

use App\Models\Vendor;
use App\Services\VendorRoles;
use Illuminate\Database\Seeder;

class VendorRolesBackfillSeeder extends Seeder
{
    public function run(): void
    {
        Vendor::all()->each(fn (Vendor $vendor) => VendorRoles::seedFor($vendor));
    }
}
