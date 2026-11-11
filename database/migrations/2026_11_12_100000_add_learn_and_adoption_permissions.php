<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Data migration for in-app tips Phase 3.
//
// - view_learn_page: opens Learn GadgetPlug in the vendor panel. Granted to
//   every role every business already has (VendorRoles gives it to the
//   default roles of new businesses), so on deploy nobody loses a page they
//   should see; a vendor can take it away on their Roles screen. Add-only: it
//   never touches anything else a vendor has set on a role.
// - view_feature_adoption: the admin Feature Adoption report. Global, and
//   granted to nobody; super admins see it anyway.
//
// Created here as well as in VendorPermissionsSeeder because the seeder does
// not run on deploy. Query builder only, so it runs the same on MySQL and
// SQLite.
return new class extends Migration
{
    private const LEARN = 'view_learn_page';

    private const ADOPTION = 'view_feature_adoption';

    public function up(): void
    {
        $learnId = $this->permissionId(self::LEARN);
        $this->permissionId(self::ADOPTION);

        $alreadyHeld = DB::table('role_has_permissions')->where('permission_id', $learnId)->pluck('role_id')->flip();

        $grants = DB::table('roles')
            ->whereNotNull('team_id')
            ->where('guard_name', 'web')
            ->pluck('id')
            ->reject(fn ($roleId) => $alreadyHeld->has($roleId))
            ->map(fn ($roleId) => ['permission_id' => $learnId, 'role_id' => $roleId])
            ->values();

        foreach ($grants->chunk(500) as $chunk) {
            DB::table('role_has_permissions')->insert($chunk->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', [self::LEARN, self::ADOPTION])->pluck('id');

        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionId(string $name): int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

        if ($id) {
            return (int) $id;
        }

        $now = now();

        return (int) DB::table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
