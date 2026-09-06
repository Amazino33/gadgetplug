<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What marks a listing as resold from a supplier rather than genuinely stocked.
//
// Deliberately columns on products rather than a separate listing table: a
// linked listing is a REAL product row in the reseller's catalogue, so the
// storefront, cart, checkout, slugs, OG tags and the pixel all keep working with
// no knowledge of this feature at all. A parallel product system would have to
// re-earn every one of those.
//
// Null on both columns means an ordinary product, which is every row that
// exists today — the feature is invisible until a link is used.
//
// nullOnDelete on the source: if a supplier deletes a product, the reseller's
// listing must not vanish with it mid-order. It stops resolving and becomes a
// dead listing the reseller can see and deal with, which is recoverable;
// cascading would delete a row that order lines point at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('source_product_id')->nullable()->after('vendor_id')
                ->constrained('products')->nullOnDelete();
            $table->foreignId('supplier_link_id')->nullable()->after('source_product_id')
                ->constrained('supplier_links')->nullOnDelete();

            // Answers "is this resold" and "what did we publish from this
            // supplier already" without scanning the catalogue.
            $table->index(['supplier_link_id', 'source_product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['supplier_link_id', 'source_product_id']);
            $table->dropConstrainedForeignId('supplier_link_id');
            $table->dropConstrainedForeignId('source_product_id');
        });
    }
};
