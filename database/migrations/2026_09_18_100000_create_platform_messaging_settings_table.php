<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Platform-owned messaging settings. Single row (id = 1), same minimal pattern
// as affiliate_settings.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_messaging_settings', function (Blueprint $table) {
            $table->id();

            // Where an online order's "pack this" alert goes when the vendor has
            // not set a storekeeper number of their own. Without it that alert
            // silently went nowhere, which is invisible: no error, no log, and
            // nothing on the order to show a message was missed.
            $table->string('fallback_storekeeper_whatsapp')->nullable();

            $table->timestamps();
        });

        DB::table('platform_messaging_settings')->insert([
            'id'         => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_messaging_settings');
    }
};
