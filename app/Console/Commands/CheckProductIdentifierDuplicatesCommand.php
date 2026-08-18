<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Read-only. Answers one question before any import feature ships: can a SKU or
// barcode actually identify a product?
//
// The import matches an incoming row to an existing product by SKU first, then
// barcode. Neither column carries a unique constraint today — not even per
// vendor — so if a vendor's catalogue already holds two products with the same
// SKU, "the matching product" is ambiguous and an update would silently land on
// whichever row the database happened to return first.
//
// This reports the damage without touching anything, so the decision to add
// unique(vendor_id, sku) is made against real numbers rather than a hope that
// the data is clean. A migration adding that index fails outright on duplicate
// data, and finding out during a production deploy is the bad way to learn it.
class CheckProductIdentifierDuplicatesCommand extends Command
{
    protected $signature = 'products:check-duplicates
                            {--vendor= : Limit to one vendor id}
                            {--show=15 : How many example collisions to print per column}';

    protected $description = 'Report duplicate SKUs and barcodes per vendor, so unique indexes can be added safely';

    public function handle(): int
    {
        $vendorId = $this->option('vendor');
        $show     = max(1, (int) $this->option('show'));

        $this->line('Read-only. Nothing below writes to the database.');
        $this->newLine();

        $blockers = 0;

        foreach (['sku', 'barcode'] as $column) {
            $blockers += $this->reportColumn($column, $vendorId, $show);
        }

        $this->newLine();

        if ($blockers === 0) {
            $this->info('No duplicates. unique(vendor_id, sku) and unique(vendor_id, barcode) can both be applied safely.');

            return self::SUCCESS;
        }

        $this->warn("{$blockers} duplicate group(s) block the unique indexes.");
        $this->line('Each group needs one of: a corrected identifier, a blanked identifier, or the extra product removed.');
        $this->line('Blank and NULL values are ignored throughout — a unique index permits any number of them.');

        // Not a failure of this command; it answered the question it was asked.
        return self::SUCCESS;
    }

    private function reportColumn(string $column, ?string $vendorId, int $show): int
    {
        // NULL and '' both mean "this product has no such identifier", and a
        // unique index tolerates any number of NULLs. Counting them as
        // collisions would invent a problem that does not exist.
        $groups = DB::table('products')
            ->select('vendor_id', $column, DB::raw('COUNT(*) as total'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->groupBy('vendor_id', $column)
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('total')
            ->get();

        $label = strtoupper($column);

        if ($groups->isEmpty()) {
            $this->info("{$label}: clean — no vendor has two products sharing one.");

            return 0;
        }

        $affected = $groups->sum('total');

        $this->warn("{$label}: {$groups->count()} duplicate group(s) across {$affected} product(s).");

        $rows = [];

        foreach ($groups->take($show) as $group) {
            $names = DB::table('products')
                ->where('vendor_id', $group->vendor_id)
                ->where($column, $group->{$column})
                ->orderBy('id')
                ->pluck('name', 'id');

            $vendorName = DB::table('vendors')->where('id', $group->vendor_id)->value('name') ?? '(unknown)';

            $rows[] = [
                $group->vendor_id.' — '.$vendorName,
                $group->{$column},
                $group->total,
                collect($names)->map(fn ($name, $id) => "#{$id} {$name}")->implode('; '),
            ];
        }

        $this->table(['Vendor', $label, 'Count', 'Products'], $rows);

        if ($groups->count() > $show) {
            $this->line('  … and '.($groups->count() - $show).' more group(s). Raise --show to see them.');
        }

        $this->newLine();

        return $groups->count();
    }
}
