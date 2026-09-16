<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The figures as they stood when somebody printed them and sat down with them.
//
// There is no period lock anywhere in this system — a late correction can still
// land against a date already discussed, and that is deliberate, because
// refusing it would only push the correction somewhere nobody looks. What must
// not happen is the paper changing underneath the conversation it was printed
// for. So the numbers are copied into this row at generation and never touched
// again: reprinting last month's statement reproduces exactly what was agreed
// then, while a fresh run for the same dates shows where things stand now, and
// the two disagreeing is itself worth seeing.
//
// The payload is stored whole rather than as columns. It is a photograph, not a
// model — nothing queries inside it, and giving it a schema would invite the
// figures to be recomputed or "fixed" later, which is the one thing it exists
// to prevent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settlement_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->string('reference')->nullable()->unique();

            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Who printed it and when. A statement nobody is named on is not
            // evidence of a conversation having happened.
            $table->foreignId('generated_by')->constrained('users');
            $table->timestamp('generated_at');

            // The frozen figures, exactly as StoreReconciliation produced them.
            $table->json('payload');

            // Lifted out of the payload only so the list screen can sort and
            // filter without unpacking every row. The payload stays definitive.
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('confirmed_cash', 12, 2)->default(0);
            $table->decimal('true_shortage', 12, 2)->default(0);

            $table->timestamps();

            $table->index(['store_id', 'period_end']);
            $table->index(['vendor_id', 'generated_at']);
        });

        // What was agreed about a flagged figure, kept beside the statement
        // rather than in it. The statement is what the numbers were; this is
        // what people decided to do about them, and decisions accumulate.
        Schema::create('settlement_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_settlement_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users');

            // repayment_plan | write_off_referral | resolved_no_issue | recount | other
            $table->string('outcome', 40);
            // Which bucket this settles, e.g. true_shortage, disputed, stock.
            $table->string('concerns', 40)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('note');

            $table->timestamp('created_at')->nullable();

            $table->index('store_settlement_statement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_resolutions');
        Schema::dropIfExists('store_settlement_statements');
    }
};
