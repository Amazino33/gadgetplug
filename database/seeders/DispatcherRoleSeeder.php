<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// GadgetPlug's dispatch staff: they run online orders after the store has
// packed them — assign a logistics company, record the money collected, mark
// delivered by override, cancel, handle failed deliveries — and keep the list
// of logistics companies. Nothing else in the admin panel.
//
// GLOBAL — team_id null — like catalogue_editor. Idempotent; the migration of
// the same date calls it.
class DispatcherRoleSeeder extends Seeder
{
    public const ROLE = 'dispatcher';

    public const PERMISSION = 'manage_dispatch';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId(null);

        try {
            Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

            Role::firstOrCreate([
                'name'       => self::ROLE,
                'guard_name' => 'web',
                'team_id'    => null,
            ])->givePermissionTo(self::PERMISSION);
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
