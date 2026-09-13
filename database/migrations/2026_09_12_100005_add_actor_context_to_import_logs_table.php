<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who ran the import, and where its products landed.
//
// Platform staff onboard a vendor's catalogue for them — the vendor sends a
// spreadsheet, we run it from our end. user_id alone cannot tell that apart
// from the vendor's own storekeeper doing it: both are a foreign key to a name
// the vendor may not recognise. When a vendor asks "who put these 300 products
// here", "GadgetPlug support, on 12 September" and "your storekeeper Musa" are
// different answers with different next steps, and only one of them is a
// support ticket.
//
// store_id is here for the same reason. Which branch an import lands in is
// decided entirely by whichever store was active when it ran, which is the
// single most expensive thing to get wrong about an import and the one thing
// the log could not answer afterwards.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            // Recorded as a fact about the run, not derived from the actor's
            // roles at read time. Staff lose the super_admin role, vendors
            // gain team members, and an import that was run by us stays run by
            // us however the accounts change afterwards.
            $table->boolean('performed_by_admin')->default(false)->after('user_id');

            // Nulled rather than cascaded: closing a branch must not erase the
            // record of what was imported into it. Nullable also because every
            // row written before this migration has no store to name, and
            // guessing the default one would be a fabrication.
            $table->foreignId('store_id')->nullable()->after('performed_by_admin')
                ->constrained('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn('performed_by_admin');
        });
    }
};
