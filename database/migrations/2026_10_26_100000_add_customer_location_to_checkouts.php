<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer's own location, when they choose to share it at checkout
// ("Helps the rider find you"). Optional; the delivery fee never comes from
// it — that stays the LGA's — and it is never shown anywhere public.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->decimal('customer_latitude', 10, 7)->nullable()->after('lga_id');
            $table->decimal('customer_longitude', 10, 7)->nullable()->after('customer_latitude');
            // Metres, as the browser reported it.
            $table->unsignedInteger('customer_location_accuracy')->nullable()->after('customer_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropColumn(['customer_latitude', 'customer_longitude', 'customer_location_accuracy']);
        });
    }
};
