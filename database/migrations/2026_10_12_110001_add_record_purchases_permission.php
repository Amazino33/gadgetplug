<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * record_purchases gates the Purchase Terminal and Purchase History.
 *
 * Granted here, on deploy, to every role that can already run procurement —
 * whatever a vendor named it or however they cut it on their Roles screen —
 * so nobody who could receive stock yesterday is locked out of the screen
 * that replaced it. Add-only: no role loses anything, and roles without
 * manage_procurement are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'record_purchases', 'guard_name' => 'web']);

        Role::query()
            ->where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'manage_procurement'))
            ->get()
            ->each(fn (Role $role) => $role->hasPermissionTo($permission) || $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: removing it would strip a permission vendors may have
        // granted deliberately since.
    }
};
