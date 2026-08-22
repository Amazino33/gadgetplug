<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
                            {--force : Actually delete. Without this the command only reports.}
                            {--ignore=* : Table names that should not count as real data, e.g. --ignore=message_templates}';

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

        $tables  = $this->tablesReferencingVendors();
        $ignored = $this->option('ignore');

        $this->line('Checking '.count($tables).' vendor-owned tables per candidate.');

        if ($ignored !== []) {
            // Still counted and shown, just not treated as a reason to keep a
            // vendor alive. Naming them here rather than baking a list into the
            // command keeps the decision with the person running it.
            $this->line('Not counting as data: '.implode(', ', $ignored));
        }

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
                $counts[$vendor->id]     = collect($breakdowns[$vendor->id])
                    ->reject(fn ($count, $table) => in_array($table, $ignored, true))
                    ->sum();
            }

            // Keep whichever copy actually holds data. On a tie the oldest wins,
            // since that is the one whose id is likely referenced elsewhere.
            $keepId = collect($counts)
                ->sortByDesc(fn ($count, $id) => [$count, -$id])
                ->keys()
                ->first();

            foreach ($vendors as $vendor) {
                $count  = $counts[$vendor->id];
                $detail = $this->describe($breakdowns[$vendor->id], $ignored);

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

        $deleted    = 0;
        $roleIssues = 0;

        foreach ($deletable as $vendor) {
            // One transaction per vendor rather than one for the whole run: a
            // problem with the ninth copy should not roll back the eight that
            // were already dealt with cleanly.
            DB::transaction(function () use ($vendor) {
                $vendor->delete();
            });

            $deleted++;

            // Tidying the Spatie roles is separate and deliberately allowed to
            // fail. They have no foreign key to vendors, so they are orphaned
            // rows rather than anything holding the vendor in place, and the
            // seeder recreates them idempotently. Losing the whole cleanup to a
            // constraint on the permission tables would be a poor trade.
            try {
                $this->purgeRoles($vendor->id);
            } catch (\Throwable $e) {
                $roleIssues++;
                $this->warn("  vendor #{$vendor->id}: removed, but its roles could not be tidied — {$e->getMessage()}");
            }
        }

        $this->info("Deleted {$deleted} empty duplicate vendor(s).");

        if ($roleIssues > 0) {
            $this->warn("{$roleIssues} vendor(s) left orphaned permission rows behind. Harmless, but 'php artisan vendor:seed-roles' will not clear them.");
        }

        return self::SUCCESS;
    }

    // Table names come from the permission config rather than being written out
    // here, so a project that renames them does not silently skip the cleanup.
    private function purgeRoles(int $vendorId): void
    {
        $tables  = config('permission.table_names');
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');

        $roleIds = DB::table($tables['roles'])->where($teamKey, $vendorId)->pluck('id');

        // Assignments first, then the pivot to permissions, then the roles —
        // children before parents, without relying on the database's cascade
        // behaviour being what we expect.
        DB::table($tables['model_has_roles'])->where($teamKey, $vendorId)->delete();

        if ($roleIds->isNotEmpty()) {
            DB::table($tables['model_has_roles'])->whereIn('role_id', $roleIds)->delete();
            DB::table($tables['role_has_permissions'])->whereIn('role_id', $roleIds)->delete();
            DB::table($tables['roles'])->whereIn('id', $roleIds)->delete();
        }
    }

    /**
     * Tables that carry a vendor_id but are a record ABOUT a vendor rather than
     * data belonging to it. A duplicate whose only trace is its own audit trail
     * is still empty, and those rows are cleaned up with the vendor anyway — so
     * counting them would keep every vendor alive forever and make this command
     * a no-op the moment activity logging was switched on.
     *
     * @var array<int, string>
     */
    private const NEVER_COUNTS_AS_DATA = ['activity_log'];

    /** @return array<int, string> */
    private function tablesReferencingVendors(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if ($table === 'vendors' || in_array($table, self::NEVER_COUNTS_AS_DATA, true)) {
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

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $ignored
     */
    private function describe(array $counts, array $ignored = []): string
    {
        if ($counts === []) {
            return '';
        }

        return '('.collect($counts)
            ->map(fn (int $count, string $table) => in_array($table, $ignored, true)
                ? "{$table}={$count} ignored"
                : "{$table}={$count}")
            ->implode(', ').')';
    }
}
