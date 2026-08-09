<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The amount GadgetPlug pays the rider/company — never a guess, so
            // nullable with no backfill. Null means "not recorded yet", not zero.
            $table->decimal('delivery_cost', 10, 2)->nullable()->after('delivery_person_id');
            $table->foreignId('financial_account_id')->nullable()->after('delivery_cost')
                ->constrained()->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('financial_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
            $table->dropColumn(['delivery_cost', 'posted_at']);
        });
    }
};
