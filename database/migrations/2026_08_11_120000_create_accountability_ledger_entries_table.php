<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-only ledger of shortage charges and recoveries.
//
// Mirrors financial_ledger_entries: never updated, never deleted, corrected
// only by posting an opposing row, balances derived on read, and a unique
// natural key as the real backstop against double-posting.
//
// vendor_id rather than team_id: the spec calls it team_id, but every other
// table in this repo scopes a store with vendor_id (Spatie's team_id is the
// permissions concept and equals the vendor id). Following the repo convention
// as the spec allows, so tenancy and the existing vendor relationships work
// without a special case.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accountability_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            // Nullable until Phase 3 introduces the case model. Deliberately not
            // constrained yet — there is no cases table to point at.
            $table->unsignedBigInteger('case_id')->nullable();

            // Who is being charged. Nullable so a loss can sit against the store
            // with nobody named, rather than forcing a guess.
            $table->foreignId('storekeeper_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('entry_type', [
                'charge',
                'recovery_cash',
                'recovery_salary',
                'recovery_manual',
                'writeoff_conversion',
            ]);

            // ── Frozen at loss establishment (count commit). Never rewritten. ──
            // Present on charge and writeoff_conversion rows; null on recoveries,
            // which reference money already established rather than establishing it.
            $table->integer('shortage_qty')->nullable();

            // COST-SENSITIVE. unit_cost_snapshot, cost_component and
            // margin_component all disclose product cost, directly or by
            // subtraction. Stored unconditionally — that is data integrity — but
            // any Phase 3/4 display must gate them behind the existing
            // view_cost_price permission. Do not invent a new one.
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable();
            $table->decimal('unit_price_snapshot', 12, 2)->nullable();
            $table->decimal('charge_amount', 12, 2)->nullable();
            $table->decimal('cost_component', 12, 2)->nullable();
            $table->decimal('margin_component', 12, 2)->nullable();

            // True when the product had no usable retail price at commit and the
            // line was charged at cost only. Surfaced so an owner can see the
            // charge was not the full retail loss.
            $table->boolean('price_fallback')->default(false);

            // Signed effect on what the storekeeper owes: a charge is positive,
            // recoveries and writeoff_conversion are negative. Outstanding is
            // simply the sum, which is why the sign lives here rather than being
            // inferred from entry_type at read time.
            $table->decimal('amount', 12, 2);

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // created_at only. There is no update path, so an updated_at column
            // would be a permanently meaningless copy of it.
            $table->timestamp('created_at')->nullable();

            // The idempotency backstop. A plain (case_id, entry_type) unique
            // cannot work while case_id is nullable — SQL treats every NULL as
            // distinct, so it would permit unlimited duplicate charges before
            // Phase 3 wires cases up. A caller-supplied natural key sidesteps
            // that and still enforces "one charge per case, one recovery per
            // recovery event" once those ids exist.
            $table->string('idempotency_key')->nullable()->unique();

            $table->index(['vendor_id', 'storekeeper_id']);
            $table->index(['vendor_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accountability_ledger_entries');
    }
};
