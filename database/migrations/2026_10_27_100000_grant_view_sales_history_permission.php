<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Data migration: creates view_sales_history and hands it to everybody who can
// already use the till — every role holding access_pos, including roles a
// vendor built themselves, and anyone given access_pos directly. Otherwise the
// full history would ship invisible to every existing cashier until an owner
// went looking for a setting they did not know existed. Owners take it away
// per role afterwards.
//
// Only ever adds. Query builder only, no raw DDL, so it runs the same on MySQL
// and on the SQLite test database.
return new class extends Migration
{
    private const PERMISSION = 'view_sales_history';

    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name'       => self::PERMISSION,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $posId = DB::table('permissions')
            ->where('name', 'access_pos')
            ->where('guard_name', 'web')
            ->value('id');

        if ($posId) {
            $roleIds = DB::table('role_has_permissions')
                ->where('permission_id', $posId)
                ->whereNotIn('role_id', DB::table('role_has_permissions')->where('permission_id', $permissionId)->select('role_id'))
                ->pluck('role_id');

            DB::table('role_has_permissions')->insert(
                $roleIds->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
            );

            $direct = DB::table('model_has_permissions')->where('permission_id', $posId)->get();

            foreach ($direct as $grant) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'model_type'    => $grant->model_type,
                    'model_id'      => $grant->model_id,
                    'team_id'       => $grant->team_id,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId) {
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
