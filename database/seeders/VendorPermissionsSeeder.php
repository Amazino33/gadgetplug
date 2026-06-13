<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class VendorPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Products
            'view_products',
            'view_any_products',
            'create_products',
            'edit_products',
            'delete_products',

            // Order Items
            'view_order_items',
            'view_any_order_items',
            'edit_order_items',

            // Vendors
            'view_vendor',
            'edit_vendor',

            // Team Members
            'view_team_members',
            'invite_team_members',
            'edit_team_members',
            'remove_team_members',

            // POS
            'access_pos',
            'void_sale',
            'process_return',
            'close_pos_session',

            // Inventory
            'manage_inventory'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}