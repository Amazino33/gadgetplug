<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Meta's browser/click IDs, captured from the _fbp/_fbc cookies at
            // checkout submission — persisted so the queued server-side
            // Purchase CAPI event can still include them even when it fires
            // later/async, disconnected from the original request's cookies.
            $table->string('fbp')->nullable()->after('payment_method');
            $table->string('fbc')->nullable()->after('fbp');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fbp', 'fbc']);
        });
    }
};
