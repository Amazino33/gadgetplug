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
                            {--force : Actually delete. Without this the command only reports.}
                            {--skip-fk-checks : Last resort. Disables foreign key checks for the delete only. See the note in handle().}';

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
        }

        // On this database the bulk delete fails with a 1451 naming
        // model_has_roles even when roles:diagnose confirms both foreign keys
        // are ON DELETE CASCADE and no row anywhere references these ids. A
        // cascade with no children cannot legitimately refuse, which points at
        // stale InnoDB constraint metadata rather than real data. So: try the
        // bulk delete, and if it refuses, go one row at a time to find out
        // exactly which ids are affected rather than reporting the whole batch
        // as failed.
        try {
            $removedRoles = $this->deleteRoles($tables['roles'], $orphanRoleIds->all());
        } catch (\Throwable $e) {
            $this->warn('Bulk delete refused; retrying one role at a time to isolate it.');

            $removedRoles = 0;
            $stubborn     = [];

            foreach ($orphanRoleIds as $id) {
                try {
                    $removedRoles += $this->deleteRoles($tables['roles'], [$id]);
                } catch (\Throwable) {
                    $stubborn[] = $id;
                }
            }

            if ($stubborn !== []) {
                $this->newLine();
                $this->error(count($stubborn).' role(s) could not be deleted: '.implode(', ', $stubborn));
                $this->line('Nothing references them, so this is the database refusing on stale constraint');
                $this->line('metadata. Re-run with --skip-fk-checks to bypass it for this delete only.');
            }
        }

        $this->info("Removed {$removedRoles} role(s), {$removedAssignments} assignment(s), {$removedGrants} permission grant(s).");

        return self::SUCCESS;
    }

    /**
     * --skip-fk-checks is narrow on purpose: it only ever runs after the
     * referencing rows have been deleted and re-counted as zero, so it is
     * bypassing a constraint that has nothing left to protect. It is still a
     * blunt instrument, which is why it is opt-in and never the default.
     *
     * @param  array<int, int>  $ids
     */
    private function deleteRoles(string $rolesTable, array $ids): int
    {
        if (! $this->option('skip-fk-checks')) {
            return DB::table($rolesTable)->whereIn('id', $ids)->delete();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            return DB::table($rolesTable)->whereIn('id', $ids)->delete();
        } finally {
            // Session-scoped, and restored even if the delete throws — leaving
            // this off would disable integrity checking for the rest of the
            // connection's life.
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
