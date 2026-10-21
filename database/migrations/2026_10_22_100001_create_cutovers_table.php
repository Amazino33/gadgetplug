<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The moment a rule change went live, recorded by the deploy itself: this
// migration runs at deploy, so "now" here is the cut-over.
//
// 'order_status_service' — when online money started going to Due from
// GadgetPlug (Prompt 3, Phase 3). A pay-on-delivery order created before it
// may have had its cash taken by the vendor's own rider, so the admin may book
// that one the old way, into the vendor's own cash account. Orders created
// after it never can.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutovers', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->timestamp('happened_at');
            $table->timestamps();
        });

        DB::table('cutovers')->insert([
            'key'         => 'order_status_service',
            'happened_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cutovers');
    }
};
