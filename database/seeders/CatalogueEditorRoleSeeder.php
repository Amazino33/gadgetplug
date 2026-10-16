<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// The platform's back-office catalogue editor: a GadgetPlug staff member who
// fixes product photos (and, later, online names) across every vendor.
//
// GLOBAL — team_id null — unlike every vendor role, because the work spans all
// vendors and happens in the admin panel, which has no tenant. Idempotent, so
// it is safe to re-run on every deploy; the migration of the same date calls it.
class CatalogueEditorRoleSeeder extends Seeder
{
    public const ROLE = 'catalogue_editor';

    public const PERMISSIONS = [
        'update_product_media',
        'update_product_online_name',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Explicitly global: with teams on, an unset team id would be filled
        // from the current tenant if one were resolved.
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId(null);

        try {
            foreach (self::PERMISSIONS as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            $role = Role::firstOrCreate([
                'name'       => self::ROLE,
                'guard_name' => 'web',
                'team_id'    => null,
            ]);

            $role->givePermissionTo(self::PERMISSIONS);
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
