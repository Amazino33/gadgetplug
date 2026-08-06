<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // recipient_type becomes a plain string rather than gaining a third ENUM
        // value. Contrary to the comment on the earlier pos_sales enum migration,
        // SQLite *does* enforce enum() — Laravel compiles it to a CHECK
        // constraint — so a MySQL-only ALTER would widen production while leaving
        // the test suite unable to insert the new value. A string column widens
        // identically on both drivers with no raw DDL, and the permitted values
        // are already enforced where they are written.
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('recipient_type')->change();
        });

        Schema::table('delivery_messages', function (Blueprint $table) {
            $table->string('recipient_type')->change();

            // A storekeeper reminder digests several outstanding orders into one
            // message, and a low-stock alert has no order at all, so the existing
            // NOT NULL order_id cannot express either.
            $table->foreignId('order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not restoring the ENUMs: any storekeeper rows written since
        // would violate them and fail the rollback. Widening back to nullable=false
        // is likewise skipped because digest rows have a null order_id.
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('recipient_type')->change();
        });
    }
};
