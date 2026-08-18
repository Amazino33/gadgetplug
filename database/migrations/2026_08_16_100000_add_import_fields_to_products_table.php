<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fields a vendor's existing POS export carries that this catalogue had nowhere
// to put.
//
// Vendors onboard from Aronium, Loyverse and plain spreadsheets with hundreds of
// products already typed up. Importing them while quietly discarding the unit,
// the supplier and the reorder point would hand back a catalogue that looks
// complete and is not, which is worse than refusing the column outright.
//
// Deliberately absent: tax rate and tax-inclusive pricing. The application has
// no tax model at all, and columns nothing reads would imply a behaviour that
// does not exist. Those columns are dropped on import, and the export says so.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Free text, not an enum: "pcs", "kg", "carton", "yard", "pair" and
            // whatever else a vendor already uses. Constraining it would reject
            // real files for no benefit — nothing computes with this.
            $table->string('measurement_unit', 32)->nullable()->after('brand');

            // Nulled rather than cascaded: losing a supplier record must not
            // delete the products bought from them.
            $table->foreignId('supplier_id')->nullable()->after('measurement_unit')
                ->constrained('suppliers')->nullOnDelete();

            // Distinct from low_stock_threshold, which is only a display
            // warning. This is the level at which the vendor intends to reorder,
            // and preferred_quantity is how much they buy when they do.
            $table->unsignedInteger('reorder_point')->nullable()->after('low_stock_threshold');
            $table->unsignedInteger('preferred_quantity')->nullable()->after('reorder_point');

            // Services have no stock and must never appear in a count, a
            // shortage case or a restock report.
            $table->boolean('is_service')->default(false)->after('show_in_pos');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn([
                'measurement_unit',
                'reorder_point',
                'preferred_quantity',
                'is_service',
            ]);
        });
    }
};
