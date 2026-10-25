<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('affiliate_applications', function (Blueprint $table) {
            $table->foreignId('state_id')->nullable()->after('whatsapp')->constrained()->nullOnDelete();
            $table->foreignId('lga_id')->nullable()->after('state_id')->constrained()->nullOnDelete();
            $table->text('address')->nullable()->after('lga_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_applications', function (Blueprint $table) {
            $table->dropForeign(['state_id']);
            $table->dropForeign(['lga_id']);
            $table->dropColumn(['state_id', 'lga_id', 'address']);
        });
    }
};
