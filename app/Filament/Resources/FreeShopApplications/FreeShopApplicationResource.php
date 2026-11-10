<?php

namespace App\Filament\Resources\FreeShopApplications;

use App\Filament\Resources\FreeShopApplications\Pages\ListFreeShopApplications;
use App\Filament\Resources\FreeShopApplications\Pages\ViewFreeShopApplication;
use App\Filament\Resources\FreeShopApplications\Schemas\FreeShopApplicationInfolist;
use App\Filament\Resources\FreeShopApplications\Tables\FreeShopApplicationsTable;
use App\Models\Vendor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Review of free (self-signup) shops: approve, reject, ask for more info,
 * suspend, reinstate. Only plan = free vendors appear here; the paid ones
 * stay under Vendors.
 *
 * Named "Free Shop Applications" because "Vendor Applications" is already the
 * older become-a-plug flow (VendorApplicationResource) in the same group.
 */
class FreeShopApplicationResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static ?string $slug = 'free-shop-applications';

    // Keyed on id, not the vendor's slug: a pending shop can change its link
    // name, and the review link in the admin's email must keep working.
    protected static ?string $recordRouteKeyName = 'id';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Free Shop Applications';

    protected static ?string $modelLabel = 'free shop application';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('plan', Vendor::PLAN_FREE)
            ->with('user')
            ->withCount('products');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Vendor::where('plan', Vendor::PLAN_FREE)->where('status', Vendor::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return FreeShopApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FreeShopApplicationsTable::configure($table);
    }

    /**
     * Every link to a shop's page carries its id, matching $recordRouteKeyName.
     *
     * Filament reads that setting only when it opens the page, not when it
     * builds a link: a link built from the record (the table's Review button,
     * a row click) used the vendor's own route key, its link name, and the
     * page then looked that up as an id and answered 404.
     */
    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if (($parameters['record'] ?? null) instanceof Model) {
            $parameters['record'] = $parameters['record']->getKey();
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFreeShopApplications::route('/'),
            'view'  => ViewFreeShopApplication::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
