<?php

namespace App\Filament\Vendor\Resources\Roles\Pages;

use App\Filament\Vendor\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as BaseCreateRole;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Arr;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends BaseCreateRole
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissions = collect($data['permissions'] ?? []);
        $teamId = filament()->getTenant()?->id;
        $result = Arr::only($data, ['name', 'guard_name']);
        if ($teamId) {
            $result[Utils::getTenantModelForeignKey()] = $teamId;
        }
        return $result;
    }

    protected function afterCreate(): void 
    {
        $this->record->syncPermissions($this->permissions->toArray());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
