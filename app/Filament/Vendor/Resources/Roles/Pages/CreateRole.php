<?php

namespace App\Filament\Vendor\Resources\Roles\Pages;

use App\Filament\Vendor\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as BaseCreateRole;
use BezhanSalleh\FilamentShield\Support\Utils;

class CreateRole extends BaseCreateRole
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Shield only includes team_id when the team select field is visible
        // (central-app mode). In the vendor panel the field is hidden, so we
        // inject the current vendor's id here before delegating to the parent.
        $data[Utils::getTenantModelForeignKey()] = filament()->getTenant()?->id;

        return parent::mutateFormDataBeforeCreate($data);
    }
}
