<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

// Removes vendor rows created by the double-clicked "Approve" button on the
// vendor applications screen (fixed in VendorApplicationsTable, which now locks
// the row and re-checks its status inside a transaction).
//
// Deleting a vendor is not a small act: twenty-odd tables carry a vendor_id
// with cascadeOnDelete, so removing the wrong row takes its products, orders,
// POS history and ledger with it and says nothing. This command therefore
// refuses to delete any vendor that has a single attached row anywhere, works
// out which tables to check by inspecting the schema rather than a hardcoded
// list that would silently rot, and prints what it intends to do unless it is
// explicitly told to go ahead.
class CleanupDuplicateVendorsCommand extends Command
{
    protected $signature = 'vendors:cleanup-duplicates
                            {--force : Actually delete. Without this the command only reports.}';

    protected $description = 'Find vendors duplicated by repeated approval and delete the empty copies';

    public function handle(): int
    {
        $groups = Vendor::query()
            ->select('user_id', 'name', DB::raw('COUNT(*) as total'))
            ->groupBy('user_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate vendors found.');

            return self::SUCCESS;
        }

        $tables = $this->tablesReferencingVendors();
        $this->line('Checking '.count($tables).' vendor-owned tables per candidate.');
        $this->newLine();

        $deletable = [];

        foreach ($groups as $group) {
            $vendors = Vendor::where('user_id', $group->user_id)
                ->where('name', $group->name)
                ->orderBy('id')
                ->get();

            $this->line("<options=bold>{$group->name}</> — {$group->total} copies (owner #{$group->user_id})");

            $breakdowns = [];
            $counts     = [];

            foreach ($vendors as $vendor) {
                $breakdowns[$vendor->id] = $this->attachedRows($vendor, $tables);
                $counts[$vendor->id]     = array_sum($breakdowns[$vendor->id]);
            }

            // Keep whichever copy actually holds data. On a tie the oldest wins,
            // since that is the one whose id is likely referenced elsewhere.
            $keepId = collect($counts)
                ->sortByDesc(fn ($count, $id) => [$count, -$id])
                ->keys()
                ->first();

            foreach ($vendors as $vendor) {
                $count  = $counts[$vendor->id];
                $detail = $this->describe($breakdowns[$vendor->id]);

                if ($vendor->id === $keepId) {
                    $this->line("  <fg=green>keep</>   #{$vendor->id}  slug={$vendor->slug}  rows: {$count}  {$detail}");

                    continue;
                }

                if ($count > 0) {
                    $this->line("  <fg=yellow>skip</>   #{$vendor->id}  slug={$vendor->slug}  rows: {$count}  {$detail}");

                    continue;
                }

                $this->line("  <fg=red>delete</> #{$vendor->id}  slug={$vendor->slug}  attached rows: 0");
                $deletable[] = $vendor;
            }

            $this->newLine();
        }

        if ($deletable === []) {
            $this->info('Nothing safe to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn(count($deletable).' empty duplicate(s) would be deleted.');
            $this->line('Re-run with --force to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($deletable) {
            foreach ($deletable as $vendor) {
                // Spatie scopes roles by team_id with no foreign key, so these
                // would otherwise be orphaned rows pointing at a vendor that no
                // longer exists.
                $roleIds = Role::where('team_id', $vendor->id)->pluck('id');

                if ($roleIds->isNotEmpty()) {
                    DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();
                    DB::table('model_has_roles')->whereIn('role_id', $roleIds)->delete();
                    Role::whereIn('id', $roleIds)->delete();
                }

                DB::table('model_has_roles')->where('team_id', $vendor->id)->delete();

                $vendor->delete();
            }
        });

        $this->info('Deleted '.count($deletable).' empty duplicate vendor(s).');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function tablesReferencingVendors(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if ($table === 'vendors') {
                continue;
            }

            if (Schema::hasColumn($table, 'vendor_id')) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Row counts per table, so the report says what is actually holding a
     * vendor down rather than only how much. A bare total cannot distinguish
     * real trading history from rows something seeded automatically.
     *
     * @param  array<int, string>  $tables
     * @return array<string, int>
     */
    private function attachedRows(Vendor $vendor, array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $count = DB::table($table)->where('vendor_id', $vendor->id)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        arsort($counts);

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    private function describe(array $counts): string
    {
        if ($counts === []) {
            return '';
        }

        return '('.collect($counts)
            ->map(fn (int $count, string $table) => "{$table}={$count}")
            ->implode(', ').')';
    }
}
