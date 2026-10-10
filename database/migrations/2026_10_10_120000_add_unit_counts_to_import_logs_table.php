<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What an import did beyond products: selling units, barcodes and opening
// stock, and every warning it raised — so the summary can say all of it and
// the vendor can download the full list against row numbers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->unsignedInteger('units_created_count')->default(0)->after('skipped_count');
            $table->unsignedInteger('units_updated_count')->default(0)->after('units_created_count');
            $table->unsignedInteger('barcodes_added_count')->default(0)->after('units_updated_count');
            $table->unsignedInteger('opening_stock_count')->default(0)->after('barcodes_added_count');
            // [{line, name, message}] — things worth knowing that did not stop
            // a row. Errors (rows that were not imported) stay in `errors`.
            $table->json('warnings')->nullable()->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropColumn([
                'units_created_count',
                'units_updated_count',
                'barcodes_added_count',
                'opening_stock_count',
                'warnings',
            ]);
        });
    }
};
