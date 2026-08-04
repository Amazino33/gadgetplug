<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Read-only. Answers why MySQL refuses to delete roles that nothing appears to
// reference: roles:purge-orphans counted zero assignments and zero grants, then
// still hit a 1451 naming model_has_roles. Something is referencing those rows
// that an ordinary query does not show, so this dumps the actual foreign keys
// and row counts instead of reasoning about them.
class DiagnoseRoleConstraintsCommand extends Command
{
    protected $signature = 'roles:diagnose';

    protected $description = 'Report every foreign key pointing at the roles table, and what references the orphans';

    public function handle(): int
    {
        $tables  = config('permission.table_names');
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
        $driver  = DB::connection()->getDriverName();

        $this->line("Driver: {$driver}");
        $this->line('Configured table names: '.json_encode($tables));
        $this->newLine();

        if ($driver !== 'mysql') {
            $this->warn('Foreign key introspection below is MySQL-specific.');

            return self::SUCCESS;
        }

        $this->line('<options=bold>Foreign keys referencing `roles`</>');
        $fks = DB::select("
            SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
                   r.DELETE_RULE, r.UPDATE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             AND r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
            WHERE k.REFERENCED_TABLE_NAME = 'roles'
              AND k.TABLE_SCHEMA = DATABASE()
        ");

        foreach ($fks as $fk) {
            $this->line("  {$fk->TABLE_NAME}.{$fk->COLUMN_NAME}  ({$fk->CONSTRAINT_NAME})  ON DELETE {$fk->DELETE_RULE}");
        }

        if ($fks === []) {
            $this->line('  none');
        }

        $this->newLine();

        $orphanIds = DB::table($tables['roles'])
            ->whereNotNull($teamKey)
            ->whereNotIn($teamKey, fn ($q) => $q->select('id')->from('vendors'))
            ->pluck('id');

        $this->line('<options=bold>Orphaned roles</>');
        $this->line('  count: '.$orphanIds->count());
        $this->line('  ids:   '.$orphanIds->implode(', '));
        $this->newLine();

        // Each referencing table gets counted directly, rather than trusting the
        // configured name to be the one the constraint actually points at.
        $this->line('<options=bold>Rows in each referencing table</>');

        foreach ($fks as $fk) {
            $total   = DB::table($fk->TABLE_NAME)->count();
            $blocking = $orphanIds->isEmpty() ? 0 : DB::table($fk->TABLE_NAME)
                ->whereIn($fk->COLUMN_NAME, $orphanIds->all())
                ->count();

            $this->line("  {$fk->TABLE_NAME}: {$total} row(s) total, {$blocking} referencing an orphan");

            if ($blocking > 0) {
                DB::table($fk->TABLE_NAME)
                    ->whereIn($fk->COLUMN_NAME, $orphanIds->all())
                    ->limit(5)
                    ->get()
                    ->each(fn ($row) => $this->line('    '.json_encode($row)));
            }
        }

        $this->newLine();
        $this->line('<options=bold>Distinct role_id values present in '.$tables['model_has_roles'].'</>');
        $present = DB::table($tables['model_has_roles'])->distinct()->pluck('role_id');
        $this->line('  '.($present->isEmpty() ? 'none' : $present->implode(', ')));

        return self::SUCCESS;
    }
}
