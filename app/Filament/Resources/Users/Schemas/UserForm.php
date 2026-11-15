<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
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

                TextInput::make('phone')
                    ->tel()
                    ->maxLength(25)
                    ->placeholder('+234 801 234 5678')
                    ->helperText('Contact phone number for this user.'),

                TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),

                Select::make('roles')
                    ->label('Global System Roles')
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
                    ->helperText('Global permissions that apply across the entire platform (e.g. Super Admin).'),

                Select::make('stores')
                    ->label('Assigned Store Branches')
                    ->relationship('stores', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->helperText('Branches this staff member is explicitly assigned to operate.'),

                Placeholder::make('store_role_info')
                    ->label('Store & Shop Roles Summary')
                    ->visible(fn (?User $record) => $record !== null)
                    ->content(function (?User $record): HtmlString {
                        if (! $record) {
                            return new HtmlString('—');
                        }

                        $roles = $record->resolvedRolesWithContext();
                        $badges = $roles->map(function ($r) {
                            $ctx = $r['context'] ? " <span class='text-xs opacity-75'>({$r['context']})</span>" : '';
                            return "<span class='inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200 border border-gray-300 dark:border-gray-700'>{$r['name']}{$ctx}</span>";
                        })->join(' ');

                        $vendors = $record->vendors()->pluck('name')->join(', ');
                        $vendorText = $vendors ? "<p class='mt-1 text-xs text-gray-600 dark:text-gray-400'>Associated Shops/Vendors: <strong>{$vendors}</strong></p>" : '';

                        return new HtmlString("<div class='space-y-1'><div class='flex flex-wrap gap-1'>{$badges}</div>{$vendorText}</div>");
                    }),
            ]);
    }
}
