<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cash handed from whoever took it to whoever is responsible for it next.
//
// Two named people on every row, because that is the whole point: a handover
// recorded by one person alone rests on the word of the person who might have
// kept it. The receiver confirms on their own screen, and until they do the
// money is outstanding on both.
//
// The amount is never edited. A submission that turns out wrong is disputed,
// not rewritten — these rows say a named person is short, and a record that can
// be quietly changed afterwards is worth nothing to the person it accuses.
// Same discipline as AccountabilityLedgerEntry, which makes the same accusation
// about stock.
//
// expected_amount is a snapshot rather than something recomputed on read: it is
// what the system said at the moment of the handover, and recomputing it later
// would quietly rewrite history every time another sale landed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('received_by')->constrained('users');
            $table->string('reference')->nullable()->unique();

            $table->decimal('amount', 12, 2);
            $table->decimal('expected_amount', 12, 2);
            // Required by the app whenever amount and expected disagree. Not a
            // database constraint because a legitimate exact match needs none,
            // and the rule belongs where it can explain itself.
            $table->text('reason')->nullable();

            $table->string('status', 20)->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->text('dispute_note')->nullable();
            // What the receiver says they actually got. Null unless disputed.
            $table->decimal('disputed_amount', 12, 2)->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['store_id', 'submitted_by']);
            $table->index(['received_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_submissions');
    }
};
