<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            $table->decimal('platform_default_reseller_discount', 5, 2)->default(10.00)->after('platform_default_rate');
        });

        DB::table('affiliate_settings')->where('id', 1)->update([
            'platform_default_reseller_discount' => 10.00,
        ]);
    }

    public function down(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            $table->dropColumn('platform_default_reseller_discount');
        });
    }
};
