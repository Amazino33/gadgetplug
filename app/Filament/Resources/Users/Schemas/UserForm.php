<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),

                TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),

                Select::make('roles')
                    ->options(function () {
                        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

                        return Role::whereNull('team_id')->pluck('name', 'name');
                    })
                    ->multiple()
                    ->searchable()
                    ->afterStateHydrated(function ($component, $record) {
                        if (! $record) {
                            return;
                        }
                        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
                        $component->state(
                            $record->roles()->whereNull('roles.team_id')->pluck('name')->toArray()
                        );
                    })
                    ->saveRelationshipsUsing(function ($record, $state) {
                        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
                        $record->unsetRelation('roles');
                        $record->syncRoles($state ?? []);
                    })
                    ->dehydrated(false)
                    ->searchable(),
            ]);
    }
}
