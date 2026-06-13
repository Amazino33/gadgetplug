<?php

namespace App\Filament\Vendor\Resources\Roles\Pages;

use App\Filament\Vendor\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as BaseEditRole;
use Illuminate\Support\Arr;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends BaseEditRole
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissions = collect($data['permissions'] ?? []);
        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $this->record->syncPermissions($this->permissions->toArray());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
