<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Single row (id = 1), the same arrangement as affiliate_settings and
// platform_messaging_settings: the free plan's limits and who hears about new
// applications. Edited on the admin's Free Plan Settings page.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_plan_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('free_plan_product_limit')->default(50);
            $table->unsignedTinyInteger('free_plan_photos_per_product')->default(3);
            $table->string('admin_notification_email')->nullable();
            $table->timestamps();
        });

        DB::table('free_plan_settings')->insert([
            'id'                           => 1,
            'free_plan_product_limit'      => 50,
            'free_plan_photos_per_product' => 3,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('free_plan_settings');
    }
};
