<?php

use App\Models\Vendor;
use App\Services\VendorRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Manage Counters": add and remove counters, reset their codes, show the
 * sign-in QR — apart from running the stock take. Until now the Counters
 * screen came with Manage Stock Takes, so every role holding that (the
 * built-in ones and any a shop made itself) is given this as well: nobody
 * loses the screen. Every shop also gets its Counter role now, so it is on
 * the Roles screen to see and change, not only after the first counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')->where('name', 'manage_counters')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name'       => 'manage_counters',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $stockTakeId = DB::table('permissions')->where('name', 'manage_stock_takes')->where('guard_name', 'web')->value('id');

        if ($stockTakeId) {
            $roleIds = DB::table('role_has_permissions')
                ->where('permission_id', $stockTakeId)
                ->whereNotIn('role_id', DB::table('role_has_permissions')->where('permission_id', $permissionId)->select('role_id'))
                ->pluck('role_id');

            DB::table('role_has_permissions')->insert(
                $roleIds->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Vendor::query()->select(['id'])->chunkById(200, function ($vendors) {
            foreach ($vendors as $vendor) {
                VendorRoles::ensureCounterRole($vendor);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'manage_counters')->value('id');

        if ($permissionId) {
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
