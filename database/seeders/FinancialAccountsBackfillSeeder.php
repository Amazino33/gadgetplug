<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\FinancialAccounts;
use App\Models\Vendor;

class FinancialAccountsBackfillSeeder extends Seeder
{
    public function run(): void
    {
        Vendor::all()->each(fn (Vendor $vendor) => FinancialAccounts::seedFor($vendor));
    }
}
