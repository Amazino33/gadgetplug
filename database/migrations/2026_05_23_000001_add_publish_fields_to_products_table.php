<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->after('slug');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->timestamp('unpublish_at')->nullable()->after('published_at');
        });

        // Existing products stay live — backfill to published with their original created_at as publish date
        DB::statement("UPDATE products SET status = 'published', published_at = created_at WHERE status = 'draft'");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['status', 'published_at', 'unpublish_at']);
        });
    }
};
