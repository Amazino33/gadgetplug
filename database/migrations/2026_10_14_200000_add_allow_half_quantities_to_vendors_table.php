<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whether a shop sells halves — ½ crate, 1½ crates.
//
// Off for every shop, which is how every shop works today. Turned on for the
// wholesale drink shops that split crates. half_quantities_disabled_at is set
// the first time it is turned off again and never cleared: offline sync uses
// "has this shop ever sold halves" to accept a half sale queued on a till
// before the switch went off, because a till's own clock cannot be trusted to
// say when that was.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('allow_half_quantities')->default(false)->after('allow_negative_stock');
            $table->timestamp('half_quantities_disabled_at')->nullable()->after('allow_half_quantities');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['allow_half_quantities', 'half_quantities_disabled_at']);
        });
    }
};
