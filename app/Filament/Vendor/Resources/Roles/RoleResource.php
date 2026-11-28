<?php

namespace App\Filament\Vendor\Resources\Roles;

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Components\Section;
use App\Support\Visibility;
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
        'access_pos', 'void_sale', 'process_return', 'close_pos_session', 'view_sales_history',
        'manage_inventory', 'perform_inventory_count', 'authorize_recount', 'adjust_stock',
        'view_inventory_reports', 'view_restock_report', 'view_audit_sessions',
        'approve_procurement', 'manage_procurement', 'record_purchases',
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
        'submit_cash', 'receive_cash',
        'manage_logistics',
        'view_payouts',
        'manage_notification_settings',
        'manage_financial_accounts',
        'manage_expenses',
        'manage_financial_reports',
        'view_reports_hub',
        'manage_stock_takes', 'perform_stock_takes', 'map_aisles', 'manage_counters',
        // What they can see (App\Support\Visibility), shown in their own
        // section of the form.
        'view_profit', 'view_sales_totals', 'view_stock_value', 'view_other_branches',
        // Learn GadgetPlug. Ticked by default on a new role (see
        // DEFAULT_ON_PERMISSIONS), so a vendor takes it away rather than
        // having to know to add it.
        'view_learn_page',
    ];

    /**
     * Ticked already when a vendor creates a new role. Only harmless,
     * everyone-should-have-it pages belong here.
     *
     * @var array<int, string>
     */
    public const DEFAULT_ON_PERMISSIONS = ['view_learn_page'];

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

    public static function canAccess(): bool
    {
        return static::canManage();
    }

    public static function canViewAny(): bool
    {
        return static::canManage();
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit($record): bool
    {
        return static::canManage();
    }

    public static function canDelete($record): bool
    {
        return static::canManage();
    }

    public static function canDeleteAny(): bool
    {
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

            // What they can SEE, apart from what they can do: being able to
            // sell or count does not mean seeing what the business makes.
            Section::make('What they can see')
                ->description('Figures people in this role are shown. The owner always sees everything.')
                ->schema([
                    CheckboxList::make('visibility')
                        ->hiddenLabel()
                        ->options(fn () => self::visibilityPermissions()->mapWithKeys(fn (Permission $p) => [(string) $p->id => Visibility::LABELS[$p->name][0]])->all())
                        ->descriptions(fn () => self::visibilityPermissions()->mapWithKeys(fn (Permission $p) => [(string) $p->id => Visibility::LABELS[$p->name][1]])->all())
                        ->formatStateUsing(fn ($record) => $record
                            ? $record->permissions()->whereIn('name', array_keys(Visibility::LABELS))->pluck('permissions.id')->map(fn ($id) => (string) $id)->all()
                            : [])
                        ->dehydrated(false)
                        ->bulkToggleable()
                        ->columns(['default' => 1, 'md' => 2]),
                ]),

            CheckboxList::make('permissions')
                ->label('What they can do')
                ->relationship(
                    'permissions',
                    'name',
                    fn ($query) => $query
                        ->whereIn('name', self::GRANTABLE_PERMISSIONS)
                        ->whereNotIn('name', array_keys(Visibility::LABELS))
                )
                // "pos" in a permission name is the till, which people call the
                // Sales Point — in Nigeria a "POS" is the card machine. Matched
                // as a whole word so "deposit" and "post" are left alone.
                ->getOptionLabelFromRecordUsing(fn ($record) => Str::headline(
                    preg_replace('/(^|_)pos(?=_|$)/', '$1sales_point', $record->name)
                ))
                // Applies to the create form only; editing shows what the role holds.
                ->default(fn (): array => Permission::query()
                    ->whereIn('name', self::DEFAULT_ON_PERMISSIONS)
                    ->where('guard_name', 'web')
                    ->pluck('id')
                    ->all())
                ->bulkToggleable()
                ->columns(3),
        ]);
    }

    /**
     * The "What they can see" permissions, in the order Visibility lists them.
     *
     * @return \Illuminate\Support\Collection<int, Permission>
     */
    public static function visibilityPermissions(): \Illuminate\Support\Collection
    {
        $order = array_keys(Visibility::LABELS);

        return Permission::query()
            ->whereIn('name', $order)
            ->where('guard_name', 'web')
            ->get()
            ->sortBy(fn (Permission $p) => array_search($p->name, $order, true))
            ->values();
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
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view' => Pages\ViewRole::route('/{record}'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
