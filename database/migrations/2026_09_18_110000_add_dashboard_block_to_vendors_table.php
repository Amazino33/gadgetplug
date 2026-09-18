<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Deliberately separate from `online_sales_enabled`. That one is a
            // trading switch — it hides a vendor's products from shoppers while
            // the vendor keeps running their own store. This one is an account
            // switch: the vendor owes us money or broke the terms, so they lose
            // the back office and the till until it is settled.
            $table->boolean('dashboard_blocked')->default(false)->after('online_sales_enabled');
            $table->text('dashboard_blocked_reason')->nullable()->after('dashboard_blocked');
            $table->timestamp('dashboard_blocked_at')->nullable()->after('dashboard_blocked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['dashboard_blocked', 'dashboard_blocked_reason', 'dashboard_blocked_at']);
        });
    }
};
