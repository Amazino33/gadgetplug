<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('online_sales_enabled')->default(false)->after('is_verified');
        });

        // Preserve current live behavior for every vendor that already
        // exists — the false default only applies going forward, to
        // vendors created after this migration. Admins disable specific
        // vendors afterwards via the toggle, rather than everyone going
        // dark at once.
        DB::table('vendors')->update(['online_sales_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('online_sales_enabled');
        });
    }
};
