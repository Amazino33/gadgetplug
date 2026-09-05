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
    /**
     * Permissions a vendor may hand to a role.
     *
     * A whitelist, not every permission that exists: seeding a new one is not
     * enough to make it grantable, it has to be named here too. That gap is
     * what left manage_pickings invisible on this screen after it shipped.
     *
     * write_off_picking is absent on purpose — the owner gets it by ownership,
     * and listing it would let it be granted to a role, which is exactly what
     * its absence prevents.
     *
     * @var array<int, string>
     */
    public const GRANTABLE_PERMISSIONS = [
                        'view_products', 'view_any_products', 'create_products', 'edit_products', 'delete_products', 'view_cost_price',
                        'view_order_items', 'view_any_order_items', 'edit_order_items',
                        'view_vendor', 'edit_vendor',
                        'view_team_members', 'invite_team_members', 'edit_team_members', 'remove_team_members',
                        'access_pos', 'void_sale', 'process_return', 'close_pos_session',
                        'manage_inventory', 'perform_inventory_count', 'authorize_recount', 'adjust_stock',
                        'view_inventory_reports', 'view_restock_report', 'view_audit_sessions',
                        'approve_procurement', 'manage_procurement',
                        // Whoever is at the counter deals with the trader who
                        // walks in, so both of these are grantable to a role.
                        //
                        // write_off_picking is deliberately absent: it is the
                        // owner's alone, and hasVendorPermission() gives it to
                        // them by ownership. Listing it here would let it be
                        // granted to a role, which is exactly what being absent
                        // prevents.
                        'view_customer_debts',
                        'manage_pickings',
                        'manage_logistics',
                        'view_payouts',
                        'manage_notification_settings',
                        'manage_financial_accounts',
                        'manage_expenses',
                        'manage_financial_reports',
                        'view_reports_hub',
                    ];

    protected static ?int $navigationSort = 7;
    protected static string|null|\UnitEnum $navigationGroup = 'Settings';
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
        return $schema->columns(1)->components([
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
                    fn ($query) => $query->whereIn('name', self::GRANTABLE_PERMISSIONS)
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
