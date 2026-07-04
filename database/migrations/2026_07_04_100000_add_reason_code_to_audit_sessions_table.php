<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->enum('reason_code', [
                'Damaged in Store',
                'Suspected Theft',
                'Waybill Shortage',
                'Data Entry Error',
                'Supplier Short Delivery',
                'Other',
            ])->nullable()->after('status');

            $table->decimal('loss_value', 10, 2)->nullable()->after('reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropColumn(['reason_code', 'loss_value']);
        });
    }
};
