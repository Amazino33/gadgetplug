<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('pos_vat_enabled')->default(true)->after('account_name');
            $table->decimal('pos_vat_rate', 4, 2)->default(7.50)->after('pos_vat_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['pos_vat_enabled', 'pos_vat_rate']);
        });
    }
};
