<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Automatic re-check of approved counts.
//
// A till sale rung before a count can sync after the count was approved. The
// approval measured the shelf against books that did not yet have that sale,
// so the sale's deduction now lands a second time. The fix is posted on its
// own, as soon as the sale arrives.
//
// recheck_needed_at  set the moment such a sale lands; cleared once the
//                    correction is posted. Still set means the automatic run
//                    failed — the dashboard counts these, and the Re-check
//                    button is there for them.
// audit_sessions.stock_take_result_id
//                    the result an audit line belongs to: its approval, and
//                    each re-check correction after it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_results', function (Blueprint $table) {
            $table->timestamp('recheck_needed_at')->nullable();
            $table->index(['store_id', 'product_id', 'status'], 'str_store_product_status_idx');
        });

        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->foreignId('stock_take_result_id')
                ->nullable()
                ->constrained('stock_take_results')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_take_result_id');
        });

        Schema::table('stock_take_results', function (Blueprint $table) {
            $table->dropIndex('str_store_product_status_idx');
            $table->dropColumn('recheck_needed_at');
        });
    }
};
