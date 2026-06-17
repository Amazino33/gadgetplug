<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('location')->nullable()->after('address');
            $table->decimal('rating', 3, 1)->default(0.0)->after('location');
            $table->string('avg_delivery_days')->nullable()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['location', 'rating', 'avg_delivery_days']);
        });
    }
};
