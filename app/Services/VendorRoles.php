<?php

namespace App\Services;

use App\Models\Vendor;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class VendorRoles
{
    /**
     * Default roles and the global permission names they receive.
     * Permission names come from VendorPermissionsSeeder — keep in sync.
     */
    private const ROLES = [
        'store_admin' => [
            'view_products', 'view_any_products', 'create_products', 'edit_products', 'delete_products',
            'view_order_items', 'view_any_order_items', 'edit_order_items',
            'view_vendor', 'edit_vendor',
            'view_team_members', 'invite_team_members', 'edit_team_members', 'remove_team_members',
        ],
        'product_manager' => [
            'view_products', 'view_any_products', 'create_products', 'edit_products', 'delete_products',
            'view_order_items', 'view_any_order_items',
        ],
        'order_manager' => [
            'view_products', 'view_any_products',
            'view_order_items', 'view_any_order_items', 'edit_order_items',
        ],
        'inventory_manager' => [
            'view_products', 'view_any_products', 'create_products', 'edit_products', 'delete_products',
            'view_order_items', 'view_any_order_items', 'edit_order_items',
            'view_vendor',
            'view_team_members', 'invite_team_members', 'edit_team_members',
        ],
        'storekeeper' => [
            'view_products', 'view_any_products',
            'view_order_items', 'view_any_order_items',
        ],
        'member' => [
            'view_products', 'view_any_products',
        ],
    ];

    public static function seedFor(Vendor $vendor): void
    {
        // Clear cached permissions so new roles are visible immediately
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $roleName => $permissionNames) {
            // Idempotent — skip if this vendor already has the role
            $role = Role::firstOrCreate([
                'name'       => $roleName,
                'guard_name' => 'web',
                'team_id'    => $vendor->id,
            ]);

            $permissions = Permission::whereIn('name', $permissionNames)
                ->where('guard_name', 'web')
                ->get();

            $role->syncPermissions($permissions);
        }
    }
}
