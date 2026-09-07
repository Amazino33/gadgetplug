<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a store actually is.
 *
 * The feed post names the vendor, so the location belongs to the vendor rather
 * than to a branch in `stores`: pairing a vendor's name with one branch's city
 * would tell the customer something that is only sometimes true.
 *
 * Both nullable, and no backfill. Every existing store has no location and the
 * card is built to show just the name in that case, so this ships without
 * anybody having to fill anything in first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('whatsapp');
            $table->string('state', 100)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['city', 'state']);
        });
    }
};
