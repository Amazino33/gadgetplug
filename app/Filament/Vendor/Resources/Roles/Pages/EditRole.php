<?php

namespace App\Filament\Vendor\Resources\Roles\Pages;

use App\Filament\Vendor\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as BaseEditRole;
use Illuminate\Support\Arr;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;

class EditRole extends BaseEditRole
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $permissionIds = $this->data['permissions'] ?? [];
        $permissions = Permission::whereIn('id', $permissionIds)->get();
        $this->record->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
