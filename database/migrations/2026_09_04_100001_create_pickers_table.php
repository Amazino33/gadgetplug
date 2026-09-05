<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The traders who take goods to sell in their own shop and pay when they sell.
//
// Deliberately NOT pos_customers. A customer owes money on goods that are
// already theirs; a picker holds goods that are still the vendor's and can be
// asked for back. Putting them in one table would mean one balance mixing
// "owes me for what he bought" with "is holding what I own", which are answered
// differently and settled differently. The same human can be both, and would
// then have a row in each — which is correct, because those are two separate
// relationships with the shop.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            // Where to find them — the staff know these people by name and by
            // which shop in the plaza is theirs.
            $table->string('shop')->nullable();
            $table->text('notes')->nullable();
            // Kept rather than deleted: a picker who stops trading still has a
            // history of what they took and paid, and that history must not
            // disappear with them.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['vendor_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickers');
    }
};
