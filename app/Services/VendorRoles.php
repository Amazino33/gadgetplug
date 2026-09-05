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
            'access_pos', 'void_sale', 'process_return', 'close_pos_session',
            'manage_inventory', 'view_inventory_reports', 'view_audit_sessions',
            'view_activity_log',
            'view_customer_debts',
            'authorize_recount', 'adjust_stock',
            'approve_procurement', 'manage_procurement',
            'manage_pickings',
        ],
        'product_manager' => [
            'view_products', 'view_any_products', 'create_products', 'edit_products', 'delete_products',
            'import_products', 'export_products',
            'view_order_items', 'view_any_order_items', 
            'manage_inventory',
        ],
        'order_manager' => [
            'view_products', 'view_any_products',
            'view_order_items', 'view_any_order_items', 'edit_order_items',
            'access_pos', 'void_sale', 'process_return', 'close_pos_session',
        ],
        'inventory_manager' => [
            'view_products', 'view_any_products', 'create_products', 'edit_products', 'delete_products',
            'import_products', 'export_products',
            'view_order_items', 'view_any_order_items', 'edit_order_items',
            'view_vendor',
            'view_team_members', 'invite_team_members', 'edit_team_members',
            'access_pos', 'void_sale', 'process_return', 'close_pos_session',
            'manage_inventory', 'view_inventory_reports', 'view_audit_sessions',
            'authorize_recount', 'adjust_stock',
            'approve_procurement', 'manage_procurement',
            'manage_pickings',
        ],
        'storekeeper' => [
            'view_products', 'view_any_products',
            'view_order_items', 'view_any_order_items',
            'access_pos',
            'manage_inventory', 'perform_inventory_count',
            // Whoever is at the counter has to be able to take a repayment —
            // a customer settling up should never be told to come back when
            // the owner is in. Their view is scoped to their own store, and
            // writing a debt off remains the owner's decision alone.
            'view_customer_debts',
            // Same reasoning as the debt line above: the trader comes back on
            // the day he has sold, not on the day the owner is in.
            'manage_pickings',
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
