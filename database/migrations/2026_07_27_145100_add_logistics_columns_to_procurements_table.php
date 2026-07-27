<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive only — the existing status/approval workflow (pending/approved/
// voided, ApproveProcurementAction) is left untouched. These columns back
// the auto-pricing reconciliation step being designed separately; nothing
// here changes how the current wizard/approval flow behaves.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->decimal('logistics_cost', 12, 2)->nullable()->after('total_cost');
            $table->foreignId('logistics_recorded_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logistics_recorded_by');
            $table->dropColumn(['logistics_cost', 'reconciled_at']);
        });
    }
};
