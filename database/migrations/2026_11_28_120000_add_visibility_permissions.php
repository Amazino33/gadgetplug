<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * "What they can see" on the Roles screen (App\Support\Visibility).
 *
 * Profit: until now anyone who could see sales reports saw the profit. From
 * now it is the owner's alone until the shop ticks "Profit" on a role, so it
 * is given to no role here.
 *
 * Sales totals, stock value and other branches: every role that sees them
 * today (it holds View Inventory Reports; for stock value also View Cost
 * Price or View Products) keeps them. The shop unticks
 * what it does not want a role to see.
 */
return new class extends Migration
{
    private const KEEP_FROM = [
        'view_sales_totals'   => ['view_inventory_reports'],
        // Products and Your Stores showed the stock's worth to anyone who
        // could see products: they keep it.
        'view_stock_value'    => ['view_inventory_reports', 'view_cost_price', 'view_products'],
        'view_other_branches' => ['view_inventory_reports'],
    ];

    public function up(): void
    {
        $now = now();

        $id = fn (string $name) => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $id('view_profit');

        foreach (self::KEEP_FROM as $permission => $from) {
            $permissionId = $id($permission);
            $fromIds = DB::table('permissions')->whereIn('name', $from)->where('guard_name', 'web')->pluck('id');

            $roleIds = DB::table('role_has_permissions')
                ->whereIn('permission_id', $fromIds)
                ->whereNotIn('role_id', DB::table('role_has_permissions')->where('permission_id', $permissionId)->select('role_id'))
                ->distinct()
                ->pluck('role_id');

            DB::table('role_has_permissions')->insert(
                $roleIds->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', ['view_profit', 'view_sales_totals', 'view_stock_value', 'view_other_branches'])
            ->pluck('id');

        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
