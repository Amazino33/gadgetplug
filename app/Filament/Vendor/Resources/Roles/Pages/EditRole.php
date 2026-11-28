<?php

namespace App\Filament\Vendor\Resources\Roles\Pages;

use App\Filament\Vendor\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as BaseEditRole;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends BaseEditRole
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterSave(): void
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
