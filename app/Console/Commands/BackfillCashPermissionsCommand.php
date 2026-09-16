<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Services\VendorRoles;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give existing vendors the cash-handover permissions their roles predate.
 *
 * Vendors created before submit_cash and receive_cash existed have roles that
 * were synced from an older list, so nobody on those teams can hand cash over
 * or receive it — the handover screen offers an empty dropdown and the feature
 * is simply unusable for them.
 *
 * Deliberately NOT `vendor:seed-roles`, which would fix this and also sync every
 * role back to the canonical set. Vendors can edit their own roles on the Roles
 * screen, and re-syncing would silently throw those changes away. This only ever
 * adds, never removes.
 */
class BackfillCashPermissionsCommand extends Command
{
    protected $signature = 'cash:backfill-permissions
        {vendor? : Vendor id or slug (omit for every vendor)}
        {--dry-run : List what would change, change nothing}';

    protected $description = 'Add the settlement permissions to existing vendors\' default roles without resetting them';

    /**
     * Everything the settlement feature needs a role to hold.
     *
     * Both pairs follow the same split: the person who hands cash over or counts
     * the shelf is never the person who signs it off, because in both cases they
     * are the one a shortage would be put to.
     */
    private const PERMISSIONS = [
        'submit_cash',
        'receive_cash',
        'count_stock',
        'approve_stock_count',
    ];

    public function handle(): int
    {
        $missing = collect(self::PERMISSIONS)
            ->reject(fn (string $name) => Permission::where('name', $name)->where('guard_name', 'web')->exists());

        if ($missing->isNotEmpty()) {
            $this->error('These permissions do not exist yet: ' . $missing->implode(', '));
            $this->line('Run the vendor permissions seeder first.');

            return self::FAILURE;
        }

        $vendors = $this->argument('vendor')
            ? Vendor::where('id', $this->argument('vendor'))->orWhere('slug', $this->argument('vendor'))->get()
            : Vendor::all();

        if ($vendors->isEmpty()) {
            $this->error('No matching vendor(s).');

            return self::FAILURE;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $granted = 0;

        foreach ($vendors as $vendor) {
            foreach (self::PERMISSIONS as $permission) {
                foreach (VendorRoles::rolesWith($permission) as $roleName) {
                    $role = Role::where('name', $roleName)
                        ->where('team_id', $vendor->id)
                        ->where('guard_name', 'web')
                        ->first();

                    // A vendor that never had this role is not one this command
                    // should invent it for; seeding roles is a different job.
                    if (! $role || $role->hasPermissionTo($permission)) {
                        continue;
                    }

                    $this->line(sprintf('%s (#%d): %s -> %s', $vendor->name, $vendor->id, $roleName, $permission));

                    if (! $this->option('dry-run')) {
                        $role->givePermissionTo($permission);
                    }

                    $granted++;
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($granted === 0) {
            $this->info('Nothing to do — every role already has these.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info($this->option('dry-run')
            ? "Dry run — {$granted} grant(s) would be made."
            : "Granted {$granted} permission(s).");

        return self::SUCCESS;
    }
}
