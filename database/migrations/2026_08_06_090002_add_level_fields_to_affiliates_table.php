<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            // Current level is a genuinely stored, path-dependent field — not
            // derivable, because promotion (ratchet, only moves up) and
            // demotion (moves down on inactivity) are asymmetric operations.
            // Lifetime sales value itself stays a pure derived query.
            $table->foreignId('affiliate_level_id')->nullable()->after('is_active')
                ->constrained()->nullOnDelete();
            // When the current level was set (promotion or demotion) — audit/
            // display only, and gates demotion so a single job run can only
            // ever drop one step until a full inactivity window has re-elapsed.
            $table->timestamp('level_achieved_at')->nullable()->after('affiliate_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_level_id');
            $table->dropColumn('level_achieved_at');
        });
    }
};
