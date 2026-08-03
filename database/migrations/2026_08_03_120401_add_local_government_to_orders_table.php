<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('local_government')->nullable()->after('shipping_address');
        });

        // Backfill from existing orders — checkout.blade.php built shipping_address as
        // "{lga}, Akwa Ibom State — {address}", so the LGA is reliably recoverable as a
        // fixed prefix for every historical order (only 3 possible values are ever used).
        foreach (['Uyo', 'Mkpat Enin', 'Eket'] as $lga) {
            DB::table('orders')
                ->where('shipping_address', 'like', $lga . ', Akwa Ibom State —%')
                ->update(['local_government' => $lga]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('local_government');
        });
    }
};
