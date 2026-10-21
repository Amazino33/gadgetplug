<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// orders.payment_channel stops being an ENUM of cash / bank_transfer so a
// reseller's wallet purchase can record 'wallet' as how it was paid — the same
// enum-to-string move as orders.status, identical on MySQL and SQLite. Allowed
// values are enforced in RecognizeOrderRevenueAction. Existing values are
// unchanged.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_channel', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Stays a string: narrowing back would fail on any 'wallet' row.
    }
};