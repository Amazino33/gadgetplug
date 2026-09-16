<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cash spent out of the till before it was ever handed over.
//
// A branch buys fuel for the generator, data for the terminal, a bag of water
// for a delivery. That money is genuinely gone and genuinely the shop's, but
// nothing in the takings knows it left, so at settlement it reads as a shortage
// and accuses whoever was holding the drawer.
//
// Declared at the time of spending on purpose. An expense produced afterwards,
// once a shortage has already been put to somebody, is indistinguishable from
// an excuse — the timestamp is most of what makes this worth anything.
//
// Deliberately NOT a CashUpRectification of kind 'expense'. That row belongs to
// one cash-up session's drawer-versus-terminal count, is entered by a manager at
// review, and answers "why did tonight's count differ". This answers "what left
// the till between two dates", which has no session and often no manager. The
// two overlap in meaning and must not both be counted against the same money.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('logged_by')->constrained('users');

            $table->decimal('amount', 12, 2);
            // Always required by the app. An undeclared amount leaving the till
            // is the shortage this table exists to distinguish itself from.
            $table->text('reason');

            // When the money actually left, which is not always when somebody
            // got round to recording it. The settlement statement scopes on
            // this, so an expense lands in the period it was spent in.
            $table->timestamp('spent_at');

            // The session open at the time, when there was one. Kept only so a
            // cash-up can show what has already been declared and avoid the
            // same money being explained twice.
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index(['store_id', 'spent_at']);
            $table->index(['vendor_id', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('till_expenses');
    }
};
