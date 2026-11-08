<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Data migration: creates the three assistant permissions and grants them to
// nobody. The owner holds every vendor permission already (hasVendorPermission
// short-circuits for vendors.user_id), so "owner only by default" needs no
// grant at all — a vendor hands them to a role from the Roles screen.
//
// Ported from feat/ai-assistant (1A, never merged) for Phase 1.1.
// Created here as well as in VendorPermissionsSeeder because the seeder does
// not run on deploy; a permission that exists only in the seeder is invisible
// on the Roles screen of every existing install.
//
// Query builder only, no raw DDL, so it runs the same on MySQL and SQLite.
return new class extends Migration
{
    private const PERMISSIONS = ['use_ai_assistant', 'view_ai_alerts', 'view_staff_activity'];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $name) {
            $exists = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');

        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
