<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A customer who owes money has to be findable in the physical world, which a
// name and phone alone do not manage — two people share a name, a phone gets
// changed, and the shop on the corner is what staff actually remember.
//
// No credit limit or allowed-credit flag: exposure is shown at the till so
// staff can judge it, never enforced as a gate (locked decision).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customers', function (Blueprint $table) {
            $table->string('address')->nullable()->after('email');

            // Kept separate from address rather than folded into it: "the phone
            // shop opposite the bank" is how a debt gets chased, and it is not
            // the same thing as where somebody lives.
            $table->string('shop_location')->nullable()->after('address');

            $table->text('notes')->nullable()->after('shop_location');
        });
    }

    public function down(): void
    {
        Schema::table('pos_customers', function (Blueprint $table) {
            $table->dropColumn(['address', 'shop_location', 'notes']);
        });
    }
};
