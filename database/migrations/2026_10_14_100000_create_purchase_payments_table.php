<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments to suppliers, one row each, so a purchase can be paid over time.
 *
 * procurements.amount_paid stays, as the sum of these rows: the settlement,
 * the daily summary and the old procurement screens all read it, and keeping
 * it in step is cheaper than teaching every one of them about this table.
 *
 * Also widens procurements.payment_method from its three-value enum to a short
 * string, so a purchase can be paid by POS or "other" as well. Every existing
 * value is still valid; nothing is dropped.
 *
 * And the vendor's rounding step for selling prices worked out from a
 * percentage change (₦50 unless the shop says otherwise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('procurements')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            // cash | transfer | pos | other
            $table->string('method', 20);
            $table->dateTime('paid_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'paid_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE procurements MODIFY payment_method VARCHAR(20) NOT NULL DEFAULT 'cash'");
        } else {
            Schema::table('procurements', function (Blueprint $table) {
                $table->string('payment_method', 20)->default('cash')->change();
            });
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedInteger('price_rounding_step')->default(50);
        });

        // Other units' selling prices set on a purchase line, kept with the
        // line so a draft reopens with them.
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->json('unit_prices')->nullable()->after('cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->dropColumn('unit_prices');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('price_rounding_step');
        });

        Schema::dropIfExists('purchase_payments');
    }
};
