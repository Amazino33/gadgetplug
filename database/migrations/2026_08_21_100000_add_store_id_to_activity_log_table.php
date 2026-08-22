<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Sits alongside vendor_id, which already scopes the vendor's feed.
            // Nullable on purpose: plenty of vendor actions are not about one
            // branch (editing store settings, inviting a team member), and
            // forcing a store onto those would be a lie rather than a default.
            $table->foreignId('store_id')->nullable()->after('vendor_id')
                ->constrained()->nullOnDelete();

            // The vendor feed always filters by vendor first, then optionally
            // narrows to a store, so the composite matches how it is read.
            $table->index(['vendor_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['vendor_id', 'store_id']);
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
