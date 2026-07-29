<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Schema::getIndexes() rather than "SHOW INDEX", which is MySQL-only
        // syntax and a hard error on SQLite (the test-suite driver).
        $indexes = collect(Schema::getIndexes('roles'))->pluck('name');

        $hasOldIndex = $indexes->contains('roles_name_guard_name_unique');
        $hasNewIndex = $indexes->contains('roles_team_id_name_guard_name_unique');

        Schema::table('roles', function (Blueprint $table) use ($hasOldIndex, $hasNewIndex) {
            if ($hasOldIndex) {
                $table->dropUnique('roles_name_guard_name_unique');
            }
            if (! $hasNewIndex) {
                $table->unique(['team_id', 'name', 'guard_name']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name', 'guard_name']);
            $table->unique(['name', 'guard_name']);
        });
    }


};
