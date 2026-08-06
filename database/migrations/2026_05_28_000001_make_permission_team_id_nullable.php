<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Spatie's teams migration creates team_id as part of a composite PRIMARY KEY,
// which MySQL forbids from being NULL. We replace it with a surrogate auto-increment
// PK and a unique index, allowing NULL team_id for global (cross-team) roles.
// Foreign key checks must be disabled because model_has_roles has FK constraints
// on role_id/permission_id which block the DROP PRIMARY KEY statement on older MySQL.
return new class extends Migration
{
    public function up(): void
    {
        // team_id was declared without ->nullable() in the base spatie migration,
        // so it's NOT NULL on every driver, not just MySQL's composite-PK
        // restriction — SQLite (the test-suite driver) needs the same fix, just
        // via Laravel's native column ->change() instead of raw MySQL DDL.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->change();
            });

            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->change();
            });

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // model_has_roles
        DB::statement('ALTER TABLE model_has_roles DROP PRIMARY KEY');
        DB::statement('ALTER TABLE model_has_roles ADD id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
        DB::statement('ALTER TABLE model_has_roles MODIFY team_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE model_has_roles ADD UNIQUE KEY mhr_unique (team_id, role_id, model_id, model_type(191))');

        // model_has_permissions
        DB::statement('ALTER TABLE model_has_permissions DROP PRIMARY KEY');
        DB::statement('ALTER TABLE model_has_permissions ADD id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
        DB::statement('ALTER TABLE model_has_permissions MODIFY team_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE model_has_permissions ADD UNIQUE KEY mhp_unique (team_id, permission_id, model_id, model_type(191))');

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::statement('ALTER TABLE model_has_roles DROP PRIMARY KEY');
        DB::statement('ALTER TABLE model_has_roles DROP COLUMN id');
        DB::statement('ALTER TABLE model_has_roles MODIFY team_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE model_has_roles DROP INDEX mhr_unique');
        DB::statement('ALTER TABLE model_has_roles ADD PRIMARY KEY (team_id, role_id, model_id, model_type(191))');

        DB::statement('ALTER TABLE model_has_permissions DROP PRIMARY KEY');
        DB::statement('ALTER TABLE model_has_permissions DROP COLUMN id');
        DB::statement('ALTER TABLE model_has_permissions MODIFY team_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE model_has_permissions DROP INDEX mhp_unique');
        DB::statement('ALTER TABLE model_has_permissions ADD PRIMARY KEY (team_id, permission_id, model_id, model_type(191))');

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
