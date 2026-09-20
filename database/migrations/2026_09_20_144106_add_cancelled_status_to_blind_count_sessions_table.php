<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE blind_count_sessions MODIFY COLUMN status ENUM('a_counting', 'b_counting', 'completed', 'cancelled') DEFAULT 'a_counting'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE blind_count_sessions MODIFY COLUMN status ENUM('a_counting', 'b_counting', 'completed') DEFAULT 'a_counting'");
        }
    }
};
