<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Hash;

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
                        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
                        return \Spatie\Permission\Models\Role::whereNull('team_id')->pluck('name', 'name');
                    })
                    ->multiple()
                    ->afterStateHydrated(function ($component, $record) {
                        if (! $record) return;
                        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
                        $component->state(
                            $record->roles()->whereNull('roles.team_id')->pluck('name')->toArray()
                        );
                    })
                    ->saveRelationshipsUsing(function ($record, $state) {
                        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
                        $record->unsetRelation('roles');
                        $record->syncRoles($state ?? []);
                    })
                    ->dehydrated(false)
                    ->searchable()
            ]);
    }
}
