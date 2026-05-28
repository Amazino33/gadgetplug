<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_has_roles', function (Blueprint $table) {
            if (!Schema::hasColumn('model_has_roles', 'team_id')) {
                $table->unsignedBigInteger('team_id')->nullable()->after('model_id');
                $table->index('team_id');
            }
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('model_has_permissions', 'team_id')) {
                $table->unsignedBigInteger('team_id')->nullable()->after('model_id');
                $table->index('team_id');
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'team_id')) {
                $table->unsignedBigInteger('team_id')->nullable()->after('id');
                $table->index('team_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });
    }
};