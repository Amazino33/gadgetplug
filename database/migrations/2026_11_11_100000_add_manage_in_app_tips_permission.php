<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The global permission behind the In-App Tips admin screens (tips, feature
 * copy, settings). super_admin passes without it; grant it to anyone else on
 * the platform team who writes tips. Data migration so production gets it on
 * deploy without anyone remembering to seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'manage_in_app_tips', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        Permission::where('name', 'manage_in_app_tips')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
