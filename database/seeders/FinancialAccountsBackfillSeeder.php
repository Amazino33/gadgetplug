<?php

namespace Database\Seeders;

use App\Models\Vendor;
use App\Services\FinancialAccounts;
use Illuminate\Database\Seeder;

class FinancialAccountsBackfillSeeder extends Seeder
{
    public function run(): void
    {
        Vendor::all()->each(fn (Vendor $vendor) => FinancialAccounts::seedFor($vendor));
    }
}
