<?php

namespace App\Auth;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

class VendorTeamResolver implements PermissionsTeamResolver
{
    protected int|string|null $teamId = null;

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->teamId !== null) {
            return $this->teamId;
        }

        if (filament()->hasTenancy() && $tenant = filament()->getTenant()) {
            return $tenant->id;
        }

        return null;
    }

    public function setPermissionsTeamId(Model|int|string|null $id): void
    {
        $this->teamId = $id instanceof Model ? $id->getKey() : $id;
    }

    public function getTeamId(): int|string|null
    {
        return $this->getPermissionsTeamId();
    }
}