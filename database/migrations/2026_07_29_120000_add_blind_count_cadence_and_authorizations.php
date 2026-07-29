<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Moves the count cadence off the counting screen and onto the vendor, and adds
// an auditable record of manager-authorised re-counts.
//
// Previously the counter picked their own frequency at session start, so the
// period restriction was evaluated against a value the restricted person chose
// — anyone wanting to recount simply picked the shortest window. The cadence now
// belongs to the vendor (set by an owner/manager), and the only way past it is
// an explicit authorisation recorded against the manager who granted it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('pos_blind_count_frequency')->default('daily')->after('pos_blind_count_participants');
            $table->unsignedInteger('pos_blind_count_custom_days')->nullable()->after('pos_blind_count_frequency');
        });

        // The cadence now lives on the vendor and can be 'none', which the old
        // enum(daily,weekly,monthly,custom) rejects. Nothing reads this column
        // for logic any more — it is only a historical note of what was in
        // effect when the session ran — so it no longer needs to be an enum.
        Schema::table('blind_count_sessions', function (Blueprint $table) {
            $table->string('frequency')->default('daily')->change();
        });

        Schema::create('blind_count_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            // Who is being allowed to count again
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Who allowed it — the audit trail this whole feature exists for
            $table->foreignId('granted_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Lookup is always "an unused grant for this user at this vendor"
            $table->index(['vendor_id', 'user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blind_count_authorizations');

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['pos_blind_count_frequency', 'pos_blind_count_custom_days']);
        });
    }
};
