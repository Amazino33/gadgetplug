<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('slug');
            $table->string('barcode')->nullable()->after('sku');
            $table->index('sku');
            $table->index('barcode');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('pos_pin')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->dropIndex(['barcode']);
            $table->dropColumn(['sku', 'barcode']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pos_pin');
        });
    }
};
