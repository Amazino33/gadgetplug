<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The till's own name for a held sale, so one held offline and uploaded later
// is saved once, however many times the upload is retried. Null for sales
// held before this existed, and for any till still on the older code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_suspended_sales', function (Blueprint $table) {
            $table->string('client_ref', 64)->nullable()->after('cashier_id');
            $table->unique(['vendor_id', 'client_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_suspended_sales', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
