<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coarse reach BANDS rather than exact self-reported counts, which are
        // trivially forgeable. A screenshot claiming 4,900 views and one
        // claiming 5,100 land in adjacent buckets worth a known, bounded
        // difference — so lying is worth little and reviewing is fast.
        Schema::create('affiliate_reach_bands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('min_reach');
            // Null = open-ended top band.
            $table->unsignedInteger('max_reach')->nullable();
            $table->unsignedInteger('points');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'min_reach']);
        });

        $now = now();

        DB::table('affiliate_reach_bands')->insert([
            ['name' => 'Starter (0–99 views)',    'min_reach' => 0,     'max_reach' => 99,   'points' => 5,  'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Growing (100–499)',       'min_reach' => 100,   'max_reach' => 499,  'points' => 15, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Solid (500–1,999)',       'min_reach' => 500,   'max_reach' => 1999, 'points' => 30, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Strong (2,000–9,999)',    'min_reach' => 2000,  'max_reach' => 9999, 'points' => 60, 'is_active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Viral (10,000+)',         'min_reach' => 10000, 'max_reach' => null, 'points' => 100,'is_active' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_reach_bands');
    }
};
