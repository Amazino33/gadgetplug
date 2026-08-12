<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a customer say when they want their order, straight after checkout.
//
// Both the choice and the resolved date are stored. The date alone loses the
// signal: "Today" tapped at 9pm and a scheduled date that happens to be today
// mean very different things to whoever is planning dispatch, and the urgency
// is the whole point of asking.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 'today' | 'tomorrow' | 'scheduled' — deliberately a string rather
            // than an enum: this project's enum columns need MySQL-only DDL to
            // widen later, which breaks the SQLite test suite.
            $table->string('delivery_urgency', 20)->nullable()->after('status');
            $table->date('preferred_delivery_date')->nullable()->after('delivery_urgency');
            $table->timestamp('delivery_preference_set_at')->nullable()->after('preferred_delivery_date');

            // Dispatch planning reads "what is wanted soonest, and unfulfilled"
            $table->index(['preferred_delivery_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['preferred_delivery_date', 'status']);
            $table->dropColumn([
                'delivery_urgency',
                'preferred_delivery_date',
                'delivery_preference_set_at',
            ]);
        });
    }
};
