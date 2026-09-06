<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One vendor may sell from another vendor's catalogue.
//
// Every vendor's catalogue is otherwise sealed off from every other's. This row
// IS the exception, and the only one: it is what grants a reseller read access
// to a supplier's products, and an admin has to create it deliberately. Without
// a row here there is no cross-vendor read anywhere in the system.
//
// Admin-only by policy rather than by convention, because creating one exposes a
// vendor's entire catalogue — prices included — to a different vendor. A vendor
// must never be able to grant themselves access to somebody else's shop.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('supplier_vendor_id')->constrained('vendors')->cascadeOnDelete();

            // A blanket percentage over the supplier's price. Per link, not per
            // product: the whole point is not pricing thousands of items by hand.
            $table->decimal('markup_percent', 6, 2)->default(0);

            // Names a rounding strategy rather than hardcoding one, so the
            // "ends in 990" habit can change without touching pricing logic.
            $table->string('rounding_rule', 40)->default('ends_990');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One link per pair. A second row for the same two vendors would
            // mean two markups claiming the same catalogue.
            $table->unique(['reseller_vendor_id', 'supplier_vendor_id']);
            $table->index(['reseller_vendor_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_links');
    }
};
