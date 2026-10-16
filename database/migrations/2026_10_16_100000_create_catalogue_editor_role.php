<?php

use Database\Seeders\CatalogueEditorRoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data migration: creates the global catalogue_editor role and its two
// permissions on deploy, so production never depends on someone remembering to
// run a seeder. Pure Eloquent through the seeder — no raw DDL — so it runs the
// same on MySQL and on the SQLite test database.
return new class extends Migration
{
    public function up(): void
    {
        (new CatalogueEditorRoleSeeder)->run();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')
            ->where('name', CatalogueEditorRoleSeeder::ROLE)
            ->whereNull('team_id')
            ->value('id');

        if ($roleId) {
            DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', CatalogueEditorRoleSeeder::PERMISSIONS)
            ->pluck('id');

        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
