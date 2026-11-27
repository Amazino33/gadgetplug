<?php

namespace App\Filament\Vendor\Resources\MappingTasks;

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use App\Models\Aisle;
use App\Models\StockTakeTask;
use App\Services\ActiveStore;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mapping tasks at the active branch: who was given which aisles to map, and
 * whether they have finished. Reached from Aisles, not the menu.
 */
class MappingTaskResource extends Resource
{
    protected static ?string $model = StockTakeTask::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static ?string $modelLabel = 'mapping task';

    protected static ?string $slug = 'mapping-tasks';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return AisleResource::canAccess();
    }

    public static function canCreate(): bool { return false; }

    public static function canEdit($record): bool { return false; }

    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $storeId = ActiveStore::currentId();

        return parent::getEloquentQuery()
            ->where('vendor_id', filament()->getTenant()?->id)
            ->where('type', StockTakeTask::TYPE_MAPPING)
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assignee:id,name', 'aisles']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('assignee.name')->label('Given to')->weight('bold'),
                TextColumn::make('aisles_list')
                    ->label('Aisles')
                    ->state(fn (StockTakeTask $record) => $record->aisles->map(fn (Aisle $a) => $a->label())->implode(', '))
                    ->wrap(),
                TextColumn::make('due_date')->label('Due')->date('d M Y')->placeholder('—')
                    ->color(fn (StockTakeTask $record) => $record->isOpen() && $record->due_date?->isBefore(\App\Support\Pos\BusinessDate::today()) ? 'danger' : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        StockTakeTask::STATUS_ASSIGNED    => 'Not started',
                        StockTakeTask::STATUS_IN_PROGRESS => 'Mapping',
                        StockTakeTask::STATUS_SUBMITTED   => 'Done',
                        StockTakeTask::STATUS_CANCELLED   => 'Cancelled',
                        default                           => ucfirst(str_replace('_', ' ', (string) $state)),
                    })
                    ->color(fn ($state) => match ($state) {
                        StockTakeTask::STATUS_IN_PROGRESS => 'warning',
                        StockTakeTask::STATUS_SUBMITTED   => 'success',
                        default                           => 'gray',
                    }),
                TextColumn::make('created_at')->label('Assigned')->dateTime('d M Y', 'Africa/Lagos'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open'                            => 'Open',
                        StockTakeTask::STATUS_SUBMITTED   => 'Done',
                        StockTakeTask::STATUS_CANCELLED   => 'Cancelled',
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open'   => $query->whereIn('status', StockTakeTask::OPEN_STATUSES),
                        null, '' => $query,
                        default  => $query->where('status', $data['value']),
                    }),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->modalDescription('They will no longer be able to map these aisles from this task. What they already mapped stays.')
                    ->visible(fn (StockTakeTask $record) => $record->isOpen() && AisleResource::canManage())
                    ->action(fn (StockTakeTask $record) => $record->update([
                        'status'       => StockTakeTask::STATUS_CANCELLED,
                        'cancelled_at' => now(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMappingTasks::route('/'),
        ];
    }
}
