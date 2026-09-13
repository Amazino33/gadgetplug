<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which counter the money left from.
//
// Expenses were entered from the panel, where the vendor was the only scope that
// mattered. A cashier paying the driver out of the drawer is a different event:
// it is one branch's money, on one day, and the cash-up for that drawer has to
// know about it or the cashier is short by exactly what they paid out.
//
// Nullable, because every expense recorded from the panel legitimately has no
// branch — rent and advertising belong to the business, not to a till.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'incurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'incurred_at']);
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
