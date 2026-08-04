<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Removes Spatie roles whose team_id points at a vendor that no longer exists.
//
// vendors:cleanup-duplicates deletes the vendor itself cleanly, but tidying its
// roles failed on MySQL with a 1451 against model_has_roles even though the
// assignments were deleted first. Rather than guess again, this reports the row
// counts at each step, so a failure says which statement did not remove what it
// claimed to.
class PurgeOrphanedVendorRolesCommand extends Command
{
    protected $signature = 'roles:purge-orphans
                            {--force : Actually delete. Without this the command only reports.}';

    protected $description = 'Delete vendor-scoped roles left behind by deleted vendors';

    public function handle(): int
    {
        $tables  = config('permission.table_names');
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');

        $orphanRoleIds = DB::table($tables['roles'])
            ->whereNotNull($teamKey)
            ->whereNotIn($teamKey, fn ($q) => $q->select('id')->from('vendors'))
            ->pluck('id');

        if ($orphanRoleIds->isEmpty()) {
            $this->info('No orphaned roles found.');

            return self::SUCCESS;
        }

        $assignments = DB::table($tables['model_has_roles'])->whereIn('role_id', $orphanRoleIds)->count();
        $grants      = DB::table($tables['role_has_permissions'])->whereIn('role_id', $orphanRoleIds)->count();

        $this->line("Orphaned roles: {$orphanRoleIds->count()}");
        $this->line("  assignments in {$tables['model_has_roles']}: {$assignments}");
        $this->line("  permission grants in {$tables['role_has_permissions']}: {$grants}");

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Re-run with --force to delete these.');

            return self::SUCCESS;
        }

        // Deleted in chunks: `whereIn` over hundreds of ids becomes an enormous
        // statement, and on a shared database that is worth avoiding.
        $removedAssignments = 0;
        $removedGrants      = 0;
        $removedRoles       = 0;

        foreach ($orphanRoleIds->chunk(100) as $chunk) {
            $ids = $chunk->all();

            $removedAssignments += DB::table($tables['model_has_roles'])->whereIn('role_id', $ids)->delete();
            $removedGrants      += DB::table($tables['role_has_permissions'])->whereIn('role_id', $ids)->delete();

            // Verify the children are really gone before touching the parent.
            // The previous attempt assumed this and was wrong somewhere.
            $remaining = DB::table($tables['model_has_roles'])->whereIn('role_id', $ids)->count();

            if ($remaining > 0) {
                $this->error("{$remaining} assignment(s) still reference these roles after deletion — not removing the roles.");
                $this->line('Rows still present:');

                DB::table($tables['model_has_roles'])
                    ->whereIn('role_id', $ids)
                    ->limit(5)
                    ->get()
                    ->each(fn ($row) => $this->line('  '.json_encode($row)));

                return self::FAILURE;
            }

            $removedRoles += DB::table($tables['roles'])->whereIn('id', $ids)->delete();
        }

        $this->info("Removed {$removedRoles} role(s), {$removedAssignments} assignment(s), {$removedGrants} permission grant(s).");

        return self::SUCCESS;
    }
}
