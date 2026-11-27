<?php

namespace App\Filament\Vendor\Resources\StockTakes;

use App\Models\StockTake;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\StockTakeScope;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Stock Takes: counting the active branch aisle by aisle, in tasks spread over
 * several days, while it keeps selling. Needs manage_stock_takes there.
 */
class StockTakeResource extends Resource
{
    protected static ?string $model = StockTake::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string                $navigationLabel = 'Stock Takes';
    protected static ?string                $modelLabel      = 'stock take';
    protected static ?int                   $navigationSort  = 5;

    public static function canAccess(): bool
    {
        $user    = auth()->user();
        $vendor  = filament()->getTenant();
        $storeId = ActiveStore::currentId();

        return $user && $vendor && $storeId && StockTakeAccess::canManage($user, $vendor->id, $storeId);
    }

    public static function canCreate(): bool { return static::canAccess(); }

    /** Results at this branch whose late-sale correction has not gone through. */
    public static function getNavigationBadge(): ?string
    {
        $storeId = ActiveStore::currentId();

        if (! $storeId || ! static::canAccess()) {
            return null;
        }

        $waiting = \App\Models\StockTakeResult::query()
            ->where('store_id', $storeId)
            ->whereNotNull('recheck_needed_at')
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Counts needing a re-check after late till sales';
    }

    public static function canEdit($record): bool { return false; }

    public static function canDelete($record): bool { return false; }

    /** This vendor's stock takes at the active branch. */
    public static function getEloquentQuery(): Builder
    {
        $storeId = ActiveStore::currentId();

        return parent::getEloquentQuery()
            ->where('vendor_id', filament()->getTenant()?->id)
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));
    }

    /** The fields for opening a stock take, also used to carry one over. */
    public static function openingFields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(120)->placeholder('November Full Count'),
            Grid::make(2)->schema([
                DatePicker::make('start_date')->required()->default(\App\Support\Pos\BusinessDate::today())->native(false),
                DatePicker::make('end_date')->required()->default(\App\Support\Pos\BusinessDate::now()->addDays(6)->toDateString())->native(false)->afterOrEqual('start_date'),
            ]),
            Grid::make(2)->schema([
                TextInput::make('variance_threshold_percent')
                    ->label('Recount if off by more than')
                    ->numeric()->minValue(0)->maxValue(100)->suffix('%')->default(10)->required(),
                TextInput::make('variance_threshold_value')
                    ->label('… or worth more than')
                    ->numeric()->minValue(0)->prefix('₦')->default(5000)->required(),
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->weight('bold')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('start_date')->label('Runs')
                    ->formatStateUsing(fn (StockTake $record) => $record->start_date->format('d M') . ' – ' . $record->end_date->format('d M Y')),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        StockTake::STATUS_ACTIVE => 'warning',
                        StockTake::STATUS_CLOSED => 'success',
                        default                  => 'gray',
                    }),
                TextColumn::make('progress')
                    ->label('Counted')
                    ->state(function (StockTake $record) {
                        $p = StockTakeScope::progress($record);

                        return "{$p['counted']} of {$p['in_scope']} ({$p['percent']}%)";
                    }),
            ])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ResultsRelationManager::class,
            RelationManagers\TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockTakes::route('/'),
            'view'  => Pages\ViewStockTake::route('/{record}'),
        ];
    }
}
