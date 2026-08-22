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
            'view_cost_price',
            // Bulk catalogue movement. Separate from edit_products because one
            // import can rewrite every product a vendor has, which is a bigger
            // act of trust than editing them one at a time.
            'import_products',
            'export_products',

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
            'manage_inventory',
            'perform_inventory_count',
            'authorize_recount',
            'adjust_stock',
            'view_inventory_reports',
            'view_restock_report',
            'view_audit_sessions',
            'view_activity_log',
            'approve_procurement',
            'manage_procurement',

            // Logistics & Delivery Messaging
            'manage_logistics',

            // Payouts
            'view_payouts',

            // Notification Settings
            'manage_notification_settings',

            // Financial Accounts
            'manage_financial_accounts',

            // Expenses
            'manage_expenses',

            // Financial Report
            'manage_financial_reports',

            // Reports Hub
            'view_reports_hub',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}