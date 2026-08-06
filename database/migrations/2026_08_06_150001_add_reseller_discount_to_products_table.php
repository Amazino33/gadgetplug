<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable — null means "no override, fall through to category
            // discount then platform default." Same pattern as commission_rate.
            $table->decimal('reseller_discount', 5, 2)->nullable()->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reseller_discount');
        });
    }
};
