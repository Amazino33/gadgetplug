<?php

use App\Services\VendorRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Data migration: creates the three Stock Take permissions and hands each to
// the default roles VendorRoles lists for it, so the feature works for every
// existing vendor on the day it ships:
//
//   manage_stock_takes   store_admin, inventory_manager
//   map_aisles           store_admin, inventory_manager
//   perform_stock_takes  storekeeper
//
// Roles a vendor built themselves are left alone, as with export_sales. Owners
// need no grant — hasVendorPermission() gives them everything by ownership.
//
// Only ever adds. Query builder only, so it runs the same on MySQL and on the
// SQLite test database.
return new class extends Migration
{
    private const PERMISSIONS = ['manage_stock_takes', 'perform_stock_takes', 'map_aisles'];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->where('guard_name', 'web')
                ->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name'       => $permission,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $roleIds = DB::table('roles')
                ->where('guard_name', 'web')
                ->whereIn('name', VendorRoles::rolesWith($permission))
                ->whereNotIn('id', DB::table('role_has_permissions')->where('permission_id', $permissionId)->select('role_id'))
                ->pluck('id');

            DB::table('role_has_permissions')->insert(
                $roleIds->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $permissionId = DB::table('permissions')->where('name', $permission)->value('id');

            if ($permissionId) {
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
