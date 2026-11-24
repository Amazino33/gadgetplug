<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Purchase Terminal's additions. Add-only: every existing procurement and
 * product keeps working exactly as before, and reads as it always did.
 *
 *  - products.track_serial: whether receiving this product asks for one serial
 *    or IMEI per unit. Off for everything that exists, so no current product
 *    starts demanding serials it never had.
 *  - procurements.invoice_number / purchased_on: the supplier's own reference
 *    and the date on their invoice. The wizard put the reference in notes; old
 *    rows keep it there and simply have these empty.
 *  - procurement_item_serials: one row per unit received, for products that
 *    track them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_serial')->default(false)->after('is_service');
        });

        Schema::table('procurements', function (Blueprint $table) {
            $table->string('invoice_number', 100)->nullable()->after('reference');
            $table->date('purchased_on')->nullable()->after('invoice_number');
        });

        Schema::create('procurement_item_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('serial', 100);
            $table->timestamps();

            $table->index(['vendor_id', 'product_id', 'serial']);
            $table->index('serial');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_item_serials');

        Schema::table('procurements', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'purchased_on']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('track_serial');
        });
    }
};
