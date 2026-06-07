<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Services\VendorRoles;
use Illuminate\Console\Command;

class SeedVendorRolesCommand extends Command
{
    protected $signature = 'vendor:seed-roles
                            {vendor? : Vendor ID or slug (omit to seed all vendors)}
                            {--force : Re-sync permissions even if role already exists}';

    protected $description = 'Seed default Spatie roles (scoped by team_id) for one or all vendors';

    public function handle(): int
    {
        $vendors = $this->argument('vendor')
            ? $this->resolveVendor($this->argument('vendor'))
            : Vendor::all();

        if ($vendors->isEmpty()) {
            $this->error('No matching vendor(s) found.');
            return self::FAILURE;
        }

        foreach ($vendors as $vendor) {
            $this->info("Seeding roles for vendor #{$vendor->id} — {$vendor->name}");
            VendorRoles::seedFor($vendor);
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function resolveVendor(string $identifier): \Illuminate\Support\Collection
    {
        return Vendor::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->get();
    }
}
