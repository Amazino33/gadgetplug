<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One trip: what a picker took, from which branch, on which day.
//
// Carries a branch because the goods physically left one — the same rule the
// rest of the system now follows, and what lets a till show only what went out
// from where it is standing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('picker_id')->constrained('pickers');
            // Who let the goods go. Kept for the same reason a procurement
            // records its approver: somebody made this decision, and when the
            // goods do not come back that is the first question asked.
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable()->unique();
            $table->timestamp('taken_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'picker_id']);
            $table->index(['store_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickings');
    }
};
