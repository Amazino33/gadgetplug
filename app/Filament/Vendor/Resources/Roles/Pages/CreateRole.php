<?php

namespace App\Filament\Vendor\Resources\Roles\Pages;

use App\Filament\Vendor\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as BaseCreateRole;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends BaseCreateRole
{
    protected static string $resource = RoleResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $teamId = filament()->getTenant()?->id;
        $result = Arr::only($data, ['name', 'guard_name']);
        if ($teamId) {
            $result[Utils::getTenantModelForeignKey()] = $teamId;
        }

        return $result;
    }

    protected function afterCreate(): void
    {
        // What they can do, and what they can see (its own section).
        $permissionIds = array_merge($this->data['permissions'] ?? [], $this->data['visibility'] ?? []);
        $permissions = Permission::whereIn('id', $permissionIds)
            ->whereIn('name', RoleResource::GRANTABLE_PERMISSIONS)
            ->get();
        $this->record->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
