<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Makes a SKU and a barcode actually identify a product within a vendor.
//
// The import matches an incoming row to an existing product by SKU first, then
// barcode. Without a constraint, two products can share either, and "the
// matching product" becomes whichever row the database happened to return -
// so a re-import could quietly rewrite the wrong one.
//
// The pre-check exists because a unique index applied to duplicate data fails
// with a bare SQL error naming neither the vendor nor the value. Finding that
// out mid-deploy, from "Integrity constraint violation: 1062", is the bad way
// to learn it. This stops first and says exactly what to run.
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstDuplicates();

        Schema::table('products', function (Blueprint $table) {
            // Scoped to the vendor, never global: two shops selling the same
            // Anker charger will both carry the manufacturer's barcode, and
            // neither has any claim on the other's SKUs.
            //
            // NULL and '' are untouched by this - a product with no SKU stays
            // legal, and any number of them can coexist.
            $table->unique(['vendor_id', 'sku']);
            $table->unique(['vendor_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'sku']);
            $table->dropUnique(['vendor_id', 'barcode']);
        });
    }

    private function guardAgainstDuplicates(): void
    {
        foreach (['sku', 'barcode'] as $column) {
            $groups = DB::table('products')
                ->select('vendor_id', $column, DB::raw('COUNT(*) as total'))
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy('vendor_id', $column)
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($groups->isEmpty()) {
                continue;
            }

            $examples = $groups->take(5)
                ->map(fn ($g) => sprintf('vendor %d has %d products with %s "%s"', $g->vendor_id, $g->total, $column, $g->{$column}))
                ->implode('; ');

            throw new RuntimeException(
                sprintf(
                    "Cannot make %s unique: %d duplicate group(s) exist. %s. "
                    ."Run 'php artisan products:check-duplicates' for the full list, clear them, then migrate again. "
                    .'Nothing has been changed.',
                    $column,
                    $groups->count(),
                    $examples,
                ),
            );
        }
    }
};
