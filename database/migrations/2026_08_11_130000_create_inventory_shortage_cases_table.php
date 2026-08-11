<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One case per non-zero variance count line: who is answerable, and what the
// owner decided. Deliberately separate from the stock correction, which already
// happened at commit — fixing the shelf figure must never wait on a decision
// about a person.
//
// count_line_id points at audit_sessions. There is no separate count-line table
// in this repo; audit_sessions is already one row per product per count, so it
// is the line.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_shortage_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('count_line_id')->constrained('audit_sessions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Nullable by design. This store has no "assigned storekeeper" to
            // default to — vendor_users carries no role, and any number of users
            // can hold the Spatie storekeeper role on one store. Rather than
            // guess a name and have it end up owing money, the case opens
            // unattributed and the owner must name someone before charging.
            $table->foreignId('charged_storekeeper_id')->nullable()->constrained('users')->nullOnDelete();

            // ── Frozen at open. Never recalculated, including across an
            // investigating -> charged transition. Mirrors the Phase 2 ledger
            // columns so the charge can be posted from these without going back
            // to the product, whose price will have moved on.
            $table->integer('shortage_qty');
            // COST-SENSITIVE: unit_cost_snapshot, cost_component and
            // margin_component disclose cost directly or by subtraction. Display
            // must gate them behind the existing view_cost_price permission.
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable();
            $table->decimal('unit_price_snapshot', 12, 2)->nullable();
            $table->decimal('charge_amount', 12, 2)->default(0);
            $table->decimal('cost_component', 12, 2)->default(0);
            $table->decimal('margin_component', 12, 2)->default(0);
            $table->boolean('price_fallback')->default(false);

            $table->enum('status', [
                'pending_disposition',
                'written_off',
                'charged',
                'investigating',
                // Charged and paid back in full. Distinct from written_off:
                // the company absorbed nothing.
                'recovered',
            ])->default('pending_disposition');

            $table->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disposed_at')->nullable();
            $table->text('disposition_reason')->nullable();

            $table->timestamps();

            // Re-committing a count must not open a second case for the same
            // line. The service checks first; this is what actually holds under
            // concurrency.
            $table->unique('count_line_id');

            $table->index(['vendor_id', 'status']);
            $table->index(['vendor_id', 'charged_storekeeper_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_shortage_cases');
    }
};
