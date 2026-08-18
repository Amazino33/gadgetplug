<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per import attempt, kept whether it succeeded or not.
//
// An import is the only operation in this system that can rewrite hundreds of
// products in one go. When a vendor says "my prices are all wrong since
// Tuesday", this is what answers who did it, from which file, and what it
// touched — without it the activity log shows several hundred unattributed
// product edits and no way to tell they were one action.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            // Kept if the staff member later leaves: the record of what happened
            // outlives their account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('file_name');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->string('status', 20)->default('pending');

            // Why rows were skipped, and the failure if the whole run died.
            // JSON rather than a child table: it is read as a whole, never
            // queried across, and a failed import should not leave debris in a
            // second table.
            $table->json('errors')->nullable();

            // Where the pre-import snapshot of the catalogue was written, so a
            // vendor who imported the wrong file has something to restore from.
            $table->string('snapshot_path')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
