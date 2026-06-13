<?php

namespace App\Filament\Vendor\Resources\Roles;

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Str;

class RoleResource extends ShieldRoleResource
{
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    private static function canManage(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $user->isSuperAdmin() || ($vendor?->isOwner($user) && $vendor->owner_can_manage_roles);
    }
    public static function canAccess(): bool   {
        return static::canManage();
    }
    public static function canViewAny(): bool  {
        return static::canManage();
    }
    public static function canCreate(): bool   {
        return static::canManage();
    }
    public static function canEdit($record): bool   {
        return static::canManage();
    }
    public static function canDelete($record): bool {
        return static::canManage();
    }
    public static function canDeleteAny(): bool     {
        return static::canManage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Role Name')
                ->required()
                ->maxLength(255)
                ->unique(
                    table: 'roles',
                    column: 'name',
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('team_id', filament()->getTenant()?->id)
                ),

            CheckboxList::make('permissions')
                ->label('Permissions')
                ->relationship(
                    'permissions',
                    'name',
                    fn ($q) => $q->whereIn('name', [
                        'view_products', 'view_any_products', 'create_products', 'edit_products', 'delete_products',
                        'view_order_items', 'view_any_order_items', 'edit_order_items',
                        'view_vendor', 'edit_vendor',
                        'view_team_members', 'invite_team_members', 'edit_team_members', 'remove_team_members',
                        'access_pos', 'void_sale', 'process_return', 'close_pos_session',
                    ])
                )
                ->getOptionLabelFromRecordUsing(fn ($record) => Str::headline($record->name))
                ->bulkToggleable()
                ->columns(3),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $vendorId = filament()->getTenant()?->id;

        // Abort with empty result rather than leaking global (team_id = NULL) roles
        if (! $vendorId) {
            return parent::getEloquentQuery()->whereRaw('0 = 1');
        }

        return parent::getEloquentQuery()->where('team_id', $vendorId);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view'   => Pages\ViewRole::route('/{record}'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
