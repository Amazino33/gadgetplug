<?php

namespace App\Filament\Vendor\Resources\Aisles;

use App\Models\Aisle;
use App\Models\StockTakeTask;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Aisles: the places in a branch where goods sit, in the order a person walks
 * them. Stock takes are planned and counted by aisle.
 *
 * Scoped to the active branch, like every inventory screen. Seeing the list
 * needs manage_stock_takes or map_aisles; changing aisles and handing out
 * mapping tasks needs manage_stock_takes.
 */
class AisleResource extends Resource
{
    protected static ?string $model = Aisle::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-view-columns';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string                $navigationLabel = 'Aisles';
    protected static ?int                   $navigationSort  = 6;

    public static function canAccess(): bool
    {
        return self::may(StockTakeAccess::MANAGE) || self::may(StockTakeAccess::MAP);
    }

    public static function canCreate(): bool
    {
        return self::may(StockTakeAccess::MANAGE);
    }

    public static function canEdit($record): bool
    {
        // The edit page is also where the aisle's products are mapped.
        return self::may(StockTakeAccess::MANAGE) || self::may(StockTakeAccess::MAP);
    }

    public static function canDelete($record): bool
    {
        // Deactivated rather than deleted: past counts point at it.
        return false;
    }

    public static function canManage(): bool
    {
        return self::may(StockTakeAccess::MANAGE);
    }

    /** The active branch's aisles only. */
    public static function getEloquentQuery(): Builder
    {
        $storeId = ActiveStore::currentId();

        // The vendor filter is explicit rather than left to Filament's tenant
        // scope: one shop's aisles must never reach another's screen.
        return parent::getEloquentQuery()
            ->where('vendor_id', filament()->getTenant()?->id)
            ->listed()
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(80)
                ->placeholder('Aisle 1, Fridge, Store Room')
                ->disabled(fn () => ! self::canManage()),
            TextInput::make('code')
                ->label('Short code')
                ->maxLength(16)
                ->placeholder('A01')
                ->helperText('Optional. Shown on the phone and on shelf labels.')
                ->unique(
                    table: 'aisles',
                    column: 'code',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('store_id', ActiveStore::currentId()),
                )
                ->disabled(fn () => ! self::canManage()),
            Toggle::make('is_active')
                ->label('In use')
                ->default(true)
                ->helperText('Turn off instead of deleting: past counts still point at this aisle.')
                ->disabled(fn () => ! self::canManage()),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('products')
                ->withMax('mappings', 'mapped_at'))
            ->reorderable('sort_order', fn () => self::canManage())
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label('Code')->placeholder('—')->weight('bold'),
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('products_count')->label('Products')->alignCenter(),
                TextColumn::make('mappings_max_mapped_at')
                    ->label('Last mapped')
                    ->dateTime('d M Y, g:ia', 'Africa/Lagos')
                    ->placeholder('Never'),
                TextColumn::make('mapping_by')
                    ->label('Being mapped by')
                    ->state(fn (Aisle $record) => $record->tasks()
                        ->where('type', StockTakeTask::TYPE_MAPPING)
                        ->whereIn('status', StockTakeTask::OPEN_STATUSES)
                        ->with('assignee:id,name')
                        ->get()
                        ->map(fn (StockTakeTask $t) => $t->assignee?->name . ($t->due_date ? ' (due ' . $t->due_date->format('d M') . ')' : ''))
                        ->implode(', '))
                    ->placeholder('—'),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('In use')->default(true),
            ])
            ->recordActions([
                EditAction::make()->label(fn () => self::canManage() ? 'Edit' : 'Products'),
                Action::make('toggle_active')
                    ->label(fn (Aisle $record) => $record->is_active ? 'Take out of use' : 'Put back in use')
                    ->icon(fn (Aisle $record) => $record->is_active ? 'heroicon-o-archive-box-x-mark' : 'heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn () => self::canManage())
                    ->action(fn (Aisle $record) => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->toolbarActions([
                self::assignMappingAction(),
            ]);
    }

    /**
     * Hand the selected aisles to one person to map. Being given the task is
     * the grant: they can map those aisles, and only those, until it is done.
     */
    public static function assignMappingAction(): BulkAction
    {
        return BulkAction::make('assign_mapping')
            ->label('Assign mapping task')
            ->icon('heroicon-o-qr-code')
            ->visible(fn () => self::canManage())
            ->schema([
                Select::make('assigned_to')
                    ->label('Who maps them')
                    ->options(fn () => self::staffOptions())
                    ->searchable()
                    ->required(),
                DatePicker::make('due_date')->label('Due')->default(\App\Support\Pos\BusinessDate::today())->native(false),
            ])
            ->action(function (Collection $records, array $data): void {
                $vendor  = filament()->getTenant();
                $storeId = ActiveStore::currentId();
                $aisles  = $records->filter(fn (Aisle $a) => (int) $a->store_id === (int) $storeId && $a->is_active);

                if ($aisles->isEmpty() || ! array_key_exists((int) $data['assigned_to'], self::staffOptions())) {
                    Notification::make()->title('Nothing assigned')->body('Pick aisles in use at this branch, and someone who works here.')->warning()->send();

                    return;
                }

                DB::transaction(function () use ($vendor, $storeId, $aisles, $data) {
                    $task = StockTakeTask::create([
                        'vendor_id'   => $vendor->id,
                        'store_id'    => $storeId,
                        'type'        => StockTakeTask::TYPE_MAPPING,
                        'assigned_to' => (int) $data['assigned_to'],
                        'due_date'    => $data['due_date'] ?? null,
                        'created_by'  => auth()->id(),
                    ]);

                    $task->attachAisles($aisles->modelKeys());
                });

                Notification::make()
                    ->title('Mapping task assigned')
                    ->body($aisles->count() . ' aisle' . ($aisles->count() === 1 ? '' : 's') . '. It is on their phone under Stock Take → My tasks.')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * People who can be given a task at the active branch: the owner and
     * every member who may work there.
     *
     * @return array<int, string>
     */
    public static function staffOptions(): array
    {
        $vendor  = filament()->getTenant();
        $storeId = ActiveStore::currentId();

        if (! $vendor instanceof Vendor || ! $storeId) {
            return [];
        }

        return $vendor->users()->get()
            ->push(User::find($vendor->user_id))
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => StockTakeAccess::reachesBranch($u, $vendor->id, $storeId))
            ->sortBy('name')
            ->mapWithKeys(fn (User $u) => [(int) $u->id => $u->name])
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAisles::route('/'),
            'edit'  => Pages\EditAisle::route('/{record}/edit'),
        ];
    }

    private static function may(string $permission): bool
    {
        $user    = auth()->user();
        $vendor  = filament()->getTenant();
        $storeId = ActiveStore::currentId();

        return $user && $vendor && $storeId
            && match ($permission) {
                StockTakeAccess::MANAGE => StockTakeAccess::canManage($user, $vendor->id, $storeId),
                StockTakeAccess::MAP    => StockTakeAccess::canMap($user, $vendor->id, $storeId),
                default                 => false,
            };
    }
}
