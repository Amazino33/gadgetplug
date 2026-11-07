<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_threads', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('user_id')
                ->constrained('stores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
