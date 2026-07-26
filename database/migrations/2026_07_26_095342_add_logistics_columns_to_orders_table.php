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
        Schema::table('orders', function (Blueprint $table) {
            // nullOnDelete — deleting a logistics company/rider shouldn't touch historical orders.
            $table->foreignId('logistics_company_id')->nullable()->after('status')->constrained('logistics_companies')->nullOnDelete();
            $table->foreignId('delivery_person_id')->nullable()->after('logistics_company_id')->constrained('delivery_persons')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logistics_company_id');
            $table->dropConstrainedForeignId('delivery_person_id');
        });
    }
};
