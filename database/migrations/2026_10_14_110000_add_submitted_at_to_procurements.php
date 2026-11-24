<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a purchase was sent from the Purchase Terminal for someone else to
 * approve. Such a purchase sits as `pending` — the state every delivery
 * awaiting a check already uses — and this marks it as one the terminal's
 * approvers can receive from Purchase History. Null on every existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
