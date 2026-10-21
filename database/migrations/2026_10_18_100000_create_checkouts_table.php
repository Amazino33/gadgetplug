<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Checkout split.
//
// One customer checkout becomes a `checkouts` row holding everything the
// customer did once — who they are, where it goes, how they pay, the Paystack
// reference, the duplicate-submission key, the delivery fee and the grand
// total. Each vendor in the basket gets its own child `orders` row with only
// that vendor's lines and subtotal, so vendors and stores only ever see their
// own order.
//
// state_id / lga_id are plain nullable columns for now: the states and lgas
// tables arrive with the delivery-locations phase, which adds the foreign keys.
// delivery_fee stays 0 until then.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            // The one reference the customer sees, and the one Paystack is paid against.
            $table->string('reference')->unique();
            // sha256 hex — the duplicate-submission guard moves here from orders.
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone');
            $table->text('shipping_address');
            $table->string('local_government')->nullable();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->unsignedBigInteger('lga_id')->nullable()->index();

            $table->string('payment_method');
            $table->decimal('items_total', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->json('delivery_rule_snapshot')->nullable();
            $table->decimal('grand_total', 12, 2)->default(0);

            // Meta browser/server dedupe inputs, captured once at checkout.
            $table->string('fbp')->nullable();
            $table->string('fbc')->nullable();
            // Set by whichever child order first reaches confirmed/paid, so the
            // Purchase conversion is sent once per checkout, never per order.
            $table->timestamp('purchase_tracked_at')->nullable();

            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            // Nullable: orders created outside the checkout flow (tests, manual
            // fixes) and the moment between this migration and its backfill.
            // nullOnDelete rather than cascade — an order is financial history
            // and must never disappear because its parent row did.
            $table->foreignId('checkout_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkout_id');
        });

        Schema::dropIfExists('checkouts');
    }
};
