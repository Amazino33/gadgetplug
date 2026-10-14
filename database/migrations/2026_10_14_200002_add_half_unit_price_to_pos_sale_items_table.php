<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What the half of a "1½ Crate" line was charged at.
//
// The line's money is already exact in `total`; this keeps the split so a
// receipt can show "+ ½ at ₦5,200" and a return can refund that half at what
// it actually cost the customer. Null on every line without a half in it,
// which is every line of every shop that does not sell halves.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->decimal('half_unit_price', 12, 2)->nullable()->after('sold_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->dropColumn('half_unit_price');
        });
    }
};
