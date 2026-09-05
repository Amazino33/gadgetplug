<?php

namespace App\Filament\Vendor\Resources\Pickers\RelationManagers;

use App\Actions\Pickings\ReturnFromPickerAction;
use App\Actions\Pickings\WriteOffPickingAction;
use App\Models\PickingItem;
use App\Policies\PickingWriteOffPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * What this picker has taken, and where each line stands.
 *
 * Every figure is summed from the ledger by subquery rather than read from a
 * column, so a line cannot say it is settled while its history says otherwise.
 */
class HoldingsRelationManager extends RelationManager
{
    protected static string $relationship = 'pickingItems';

    protected static ?string $title = 'Goods taken';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['product', 'picking.store'])
                ->selectRaw('picking_items.*')
                ->selectRaw(self::sumOf(null) . ' as accounted_units')
                ->selectRaw(self::sumOf('return') . ' as returned_units')
                ->selectRaw(self::sumOf('payment') . ' as paid_units')
                ->selectRaw(self::sumOf('writeoff') . ' as written_units'))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (PickingItem $record) => collect([
                        $record->picking?->reference,
                        $record->picking?->store?->name,
                        $record->picking?->taken_at?->format('d M Y'),
                    ])->filter()->implode(' · ')),

                TextColumn::make('quantity')
                    ->label('Took')
                    ->alignEnd(),

                TextColumn::make('paid_units')
                    ->label('Paid for')
                    ->alignEnd()
                    ->color('success'),

                TextColumn::make('returned_units')
                    ->label('Returned')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('written_units')
                    ->label('Written off')
                    ->alignEnd()
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('held')
                    ->label('Still holding')
                    ->state(fn (PickingItem $record) => self::stillHeld($record))
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->alignEnd(),
            ])
            ->defaultSort('picking_items.id', 'desc')
            ->recordActions([
                $this->returnAction(),
                $this->writeOffAction(),
            ]);
    }

    /**
     * Units accounted for on a line, optionally of one kind.
     *
     * A correlated subquery per line rather than a join, because joining the
     * ledger to a table already listing one row per line would multiply those
     * rows and every total with them.
     */
    private static function sumOf(?string $direction): string
    {
        $filter = $direction ? " and direction = '" . $direction . "'" : '';

        return '(select coalesce(sum(quantity), 0) from picking_ledger_entries'
            . ' where picking_item_id = picking_items.id' . $filter . ')';
    }

    private static function stillHeld(PickingItem $record): int
    {
        return max(0, $record->quantity - (int) $record->accounted_units);
    }

    private function returnAction(): Action
    {
        return Action::make('return')
            ->label('Returned')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (PickingItem $record) => self::stillHeld($record) > 0)
            ->modalHeading('Goods brought back')
            ->modalDescription('They go back on the shelf of the branch they left.')
            ->schema(fn (PickingItem $record) => [
                TextInput::make('quantity')
                    ->label('How many came back')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(self::stillHeld($record))
                    ->default(self::stillHeld($record))
                    ->required(),

                Textarea::make('note')->label('Note')->rows(2),
            ])
            ->action(function (PickingItem $record, array $data): void {
                try {
                    app(ReturnFromPickerAction::class)->execute(
                        item: $record,
                        quantity: (int) $data['quantity'],
                        userId: auth()->id(),
                        note: $data['note'] ?? null,
                    );

                    Notification::make()->title('Back on the shelf')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Nothing changed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function writeOffAction(): Action
    {
        return Action::make('writeOff')
            ->label('Write off')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            // The owner's decision alone — see PickingWriteOffPolicy for why it
            // deliberately does not also block whoever released the goods.
            ->visible(fn (PickingItem $record) => app(PickingWriteOffPolicy::class)
                ->writeOff(auth()->user(), $record))
            ->modalHeading('Give up on these goods')
            ->modalDescription('Nothing returns to the shelf — these units are gone. This records that you have stopped chasing them.')
            ->schema(fn (PickingItem $record) => [
                TextInput::make('quantity')
                    ->label('How many to write off')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(self::stillHeld($record))
                    ->default(self::stillHeld($record))
                    ->required(),

                Textarea::make('note')->label('Why')->rows(2),
            ])
            ->action(function (PickingItem $record, array $data): void {
                try {
                    app(WriteOffPickingAction::class)->execute(
                        item: $record,
                        quantity: (int) $data['quantity'],
                        userId: auth()->id(),
                        note: $data['note'] ?? null,
                    );

                    Notification::make()->title('Written off')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Nothing changed')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
