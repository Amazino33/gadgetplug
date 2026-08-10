<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The fixed 3-slot model forced every held sale to compete for one of three
// numbered spots and made "resume" address a slot rather than the sale
// itself. Replaced with a plain per-vendor list, ordered by created_at, with
// no cap on how many sales can be held at once.
return new class extends Migration
{
    public function up(): void
    {
        // MySQL/InnoDB requires an index covering vendor_id to back its
        // foreign key, and the vendor_id+slot unique index was the only one
        // doing that — dropping it directly fails with "needed in a foreign
        // key constraint". Adding a plain vendor_id index first gives InnoDB
        // something else to hang the FK off of. Split into two Schema::table
        // calls so the new index exists before the drop is attempted.
        Schema::table('pos_suspended_sales', function (Blueprint $table) {
            $table->index('vendor_id');
        });

        Schema::table('pos_suspended_sales', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'slot']);
            $table->dropColumn('slot');
        });
    }

    public function down(): void
    {
        Schema::table('pos_suspended_sales', function (Blueprint $table) {
            $table->unsignedTinyInteger('slot')->default(1);
        });

        Schema::table('pos_suspended_sales', function (Blueprint $table) {
            $table->dropIndex(['vendor_id']);
            $table->unique(['vendor_id', 'slot']);
        });
    }
};
