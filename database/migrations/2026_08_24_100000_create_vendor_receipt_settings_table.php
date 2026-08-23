<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What each store's printed receipt says and how it is arranged.
//
// Its own table rather than more columns on vendors, matching
// vendor_notification_settings: receipt options only matter to whoever is
// printing, they will keep growing (a QR link and a promo banner are already
// planned), and none of them belong in every query that loads a vendor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_receipt_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();

            // ── Header ────────────────────────────────────────────────────────
            // Printed above the items. The store name always appears; everything
            // else is optional so a store can keep the paper short.
            $table->string('header_name')->nullable();          // overrides the store name when set
            $table->string('header_tagline')->nullable();
            $table->string('header_address')->nullable();
            $table->string('header_phone')->nullable();
            $table->string('header_extra')->nullable();         // RC number, TIN, anything else
            $table->boolean('show_logo')->default(false);
            $table->enum('header_alignment', ['left', 'center', 'right'])->default('center');

            // ── Body ──────────────────────────────────────────────────────────
            // Each line is switchable because thermal paper is the constraint:
            // a store printing 40 receipts an hour cares about every millimetre.
            $table->boolean('show_receipt_number')->default(true);
            $table->boolean('show_cashier')->default(true);
            $table->boolean('show_customer')->default(true);
            $table->boolean('show_datetime')->default(true);
            $table->boolean('show_item_unit_price')->default(true);

            // ── Footer ────────────────────────────────────────────────────────
            $table->text('footer_text')->nullable();
            $table->enum('footer_alignment', ['left', 'center', 'right'])->default('center');

            // Blank lines fed after the footer so the cut falls clear of the text.
            $table->unsignedTinyInteger('feed_lines')->default(2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_receipt_settings');
    }
};
