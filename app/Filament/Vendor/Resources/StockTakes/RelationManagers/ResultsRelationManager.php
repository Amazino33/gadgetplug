<?php

namespace App\Filament\Vendor\Resources\StockTakes\RelationManagers;

use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Aisle;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\User;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\StockTakeApprover;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

/**
 * Approval: one row per counted product per handed-in task. Approve corrects
 * stock by the variance; Reject leaves it; Send to Recount hands it to
 * someone who did not count it. A recount shows beside the count it
 * re-checks, and either can be chosen.
 *
 * ₦ figures are cost-derived, so they show only to those who may see cost,
 * as on every other screen.
 */
class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';

    protected static ?string $title = 'Results & approval';

    #[On('stock-take-updated')]
    public function refreshResults(): void {}

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $money = ProductForm::canSeeCostPrice();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'product:id,name,base_unit',
                'task:id,type,assigned_to',
                'task.assignee:id,name',
                'recountOf.task.assignee:id,name',
                'recountResult.task.assignee:id,name',
                'approver:id,name',
            ]))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing to decide yet')
            ->emptyStateDescription('Results appear here when a counter hands a task in.')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (StockTakeResult $r) => $this->aislesOf($r)),

                TextColumn::make('total_counted_base')
                    ->label('Counted')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => Quantity::format($state))
                    ->description(fn (StockTakeResult $r) => $this->breakdownOf($r)),

                // Found damaged or expired: on the shelf, so not missing; written off on approval.
                TextColumn::make('damaged_qty_base')
                    ->label('Damaged / expired')
                    ->state(fn (StockTakeResult $r) => (float) $r->damaged_qty_base + (float) $r->expired_qty_base)
                    ->formatStateUsing(fn ($state, StockTakeResult $r) => (float) $state > 0
                        ? implode(' · ', array_filter([
                            (float) $r->damaged_qty_base > 0 ? Quantity::format($r->damaged_qty_base) . ' damaged' : null,
                            (float) $r->expired_qty_base > 0 ? Quantity::format($r->expired_qty_base) . ' expired' : null,
                        ])) . ($money && $r->unit_cost !== null ? ' (₦' . number_format(((float) $r->damaged_qty_base + (float) $r->expired_qty_base) * (float) $r->unit_cost, 2) . ')' : '')
                        : '—')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),
                TextColumn::make('system_qty_at_count')
                    ->label('System at count')
                    ->alignEnd()
                    ->state(fn (StockTakeResult $r) => $r->applied_system_qty ?? $r->system_qty_at_count)
                    ->formatStateUsing(fn ($state) => Quantity::format($state))
                    ->description(fn (StockTakeResult $r) => $r->counted_at?->timezone('Africa/Lagos')->format('D j M, g:ia')),

                TextColumn::make('variance_qty')
                    ->label('Variance')
                    ->alignEnd()
                    ->state(fn (StockTakeResult $r) => $r->applied_adjustment_qty ?? $r->variance_qty)
                    ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '') . Quantity::format($state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                    ->weight('bold'),

                TextColumn::make('variance_value')
                    ->label('Variance ₦')
                    ->alignEnd()
                    ->visible($money)
                    ->state(fn (StockTakeResult $r) => self::valueOf($r))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state < 0 ? '−' : ($state > 0 ? '+' : '')) . '₦' . number_format(abs($state), 2))
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray')),

                TextColumn::make('task.assignee.name')
                    ->label('Counted by')
                    ->description(fn (StockTakeResult $r) => $this->pairLine($r, $money)),

                TextColumn::make('flags')
                    ->label('')
                    ->badge()
                    ->separator(',')
                    ->state(fn (StockTakeResult $r) => $this->flagsOf($r))
                    ->color(fn (string $state) => match ($state) {
                        'Recount needed'   => 'danger',
                        'Recount'          => 'info',
                        'Not on aisle list', 'Not on shelf' => 'warning',
                        'Late till sale — not yet corrected' => 'danger',
                        default            => 'gray',
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        StockTakeResult::STATUS_PENDING         => 'To decide',
                        StockTakeResult::STATUS_APPROVED        => 'Approved',
                        StockTakeResult::STATUS_REJECTED        => 'Rejected',
                        StockTakeResult::STATUS_SENT_TO_RECOUNT => 'Being recounted',
                        default                                 => (string) $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        StockTakeResult::STATUS_APPROVED        => 'success',
                        StockTakeResult::STATUS_REJECTED        => 'gray',
                        StockTakeResult::STATUS_SENT_TO_RECOUNT => 'info',
                        default                                 => 'warning',
                    })
                    ->description(fn (StockTakeResult $r) => $r->approver ? "by {$r->approver->name}" : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        StockTakeResult::STATUS_PENDING         => 'To decide',
                        StockTakeResult::STATUS_SENT_TO_RECOUNT => 'Being recounted',
                        StockTakeResult::STATUS_APPROVED        => 'Approved',
                        StockTakeResult::STATUS_REJECTED        => 'Rejected',
                    ])
                    ->default(StockTakeResult::STATUS_PENDING),
                Filter::make('flagged')->label('Flagged for recount only')->query(fn (Builder $query) => $query->where('needs_recount', true)),
                Filter::make('shortages')->label('Shortages only')->query(fn (Builder $query) => $query->where('variance_qty', '<', 0)),
                SelectFilter::make('aisle')
                    ->label('Aisle')
                    ->options(fn () => Aisle::query()
                        ->where('store_id', $this->getOwnerRecord()->store_id)
                        ->ordered()
                        ->get()
                        ->mapWithKeys(fn (Aisle $a) => [$a->id => $a->label()])
                        ->all())
                    ->query(fn (Builder $query, array $data) => blank($data['value'] ?? null) ? $query : $query->whereExists(
                        fn ($q) => $q->selectRaw('1')->from('stock_take_entries')
                            ->whereColumn('stock_take_entries.stock_take_task_id', 'stock_take_results.stock_take_task_id')
                            ->whereColumn('stock_take_entries.product_id', 'stock_take_results.product_id')
                            ->where('stock_take_entries.aisle_id', $data['value'])
                    )),
            ])
            ->headerActions([
                Action::make('approve_unflagged')
                    ->label('Approve all not flagged')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn () => 'Approves every result waiting that is within the thresholds: '
                        . $this->unflaggedCount() . ' now. Results you counted yourself are left for someone else.')
                    ->visible(fn () => $this->unflaggedCount() > 0)
                    ->action(function () {
                        $done = app(StockTakeApprover::class)->approveAllUnflagged(auth()->user(), $this->getOwnerRecord()->id);

                        Notification::make()
                            ->title("{$done['approved']} approved")
                            ->body($done['left'] > 0 ? "{$done['left']} left for someone who did not count them." : null)
                            ->success()
                            ->send();
                        $this->dispatch('stock-take-updated');
                    }),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(fn (StockTakeResult $r) => $r->recount_of_id || $r->status === StockTakeResult::STATUS_SENT_TO_RECOUNT ? 'Approve this count' : 'Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (StockTakeResult $r) => $this->decidable($r))
                    ->modalHeading(fn (StockTakeResult $r) => "Approve {$r->product->name}?")
                    ->modalDescription(fn (StockTakeResult $r) => 'Stock is corrected by the variance at the moment of counting, worked out again now — sales made since are kept.'
                        . ($r->recountOf || $r->recountResult ? ' The other count of this pair is rejected.' : ''))
                    ->schema(fn (StockTakeResult $r) => StockTakeAccess::needsOverrideNote(auth()->user(), $r) ? [
                        Textarea::make('note')
                            ->label('You counted this yourself. Why are you approving it?')
                            ->helperText('Shown on the audit line.')
                            ->required()
                            ->rows(2),
                    ] : [])
                    ->action(fn (StockTakeResult $r, array $data) => $this->attempt(
                        fn () => app(StockTakeApprover::class)->approve(auth()->user(), $r, $data['note'] ?? null),
                        'Approved — stock corrected',
                    )),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (StockTakeResult $r) => $this->decidable($r))
                    ->requiresConfirmation()
                    ->modalDescription('Stock is left as it is. The count stays on record.')
                    ->schema(fn (StockTakeResult $r) => StockTakeAccess::needsOverrideNote(auth()->user(), $r) ? [
                        Textarea::make('note')->label('You counted this yourself. Why are you deciding it?')->required()->rows(2),
                    ] : [])
                    ->action(fn (StockTakeResult $r, array $data) => $this->attempt(
                        fn () => app(StockTakeApprover::class)->reject(auth()->user(), $r, $data['note'] ?? null),
                        'Rejected',
                    )),

                Action::make('recount')
                    ->label('Send to recount')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (StockTakeResult $r) => $r->status === StockTakeResult::STATUS_PENDING
                        && StockTakeAccess::canManage(auth()->user(), (int) $r->vendor_id, (int) $r->store_id))
                    ->schema(fn (StockTakeResult $r) => $this->recountFields(collect([$r])))
                    ->action(fn (StockTakeResult $r, array $data) => $this->sendToRecount(collect([$r]), $data)),

                Action::make('recheck')
                    ->label('Re-check')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('danger')
                    // Late till sales are corrected automatically as they
                    // arrive; this is for when that run did not go through.
                    ->visible(fn (StockTakeResult $r) => $r->recheck_needed_at !== null)
                    ->requiresConfirmation()
                    ->modalDescription('A till sale from before this count arrived after it was approved, and the automatic correction did not go through. Re-check posts the difference, so the count still holds.')
                    ->action(fn (StockTakeResult $r) => $this->attempt(
                        fn () => app(StockTakeApprover::class)->recheck(auth()->user(), $r),
                        'Re-checked',
                    )),
            ])
            ->toolbarActions([
                BulkAction::make('recount_selected')
                    ->label('Send selected to recount')
                    ->icon('heroicon-o-arrow-path')
                    ->schema(fn (?Collection $records) => $this->recountFields($records ?? collect()))
                    ->action(fn (Collection $records, array $data) => $this->sendToRecount($records, $data))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /** The current ₦ figure: as applied once approved, as found before. */
    public static function valueOf(StockTakeResult $r): ?float
    {
        if ($r->unit_cost === null) {
            return null;
        }

        $qty = $r->applied_adjustment_qty ?? $r->variance_qty;

        return round($qty * $r->unit_cost, 2);
    }

    private function decidable(StockTakeResult $r): bool
    {
        $open = $r->status === StockTakeResult::STATUS_PENDING
            || ($r->status === StockTakeResult::STATUS_SENT_TO_RECOUNT && $r->recountTask && ! $r->recountTask->isOpen());

        return $open && StockTakeAccess::canDecide(auth()->user(), $r);
    }

    private function unflaggedCount(): int
    {
        return StockTakeResult::query()
            ->where('stock_take_id', $this->getOwnerRecord()->id)
            ->pending()
            ->where('needs_recount', false)
            ->count();
    }

    private function recountFields(Collection $results): array
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->getOwnerRecord();
        $vendor    = $stockTake->vendor;

        $options = $vendor->users()->get()
            ->push(User::find($vendor->user_id))
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => StockTakeAccess::canPerform($u, $vendor->id, (int) $stockTake->store_id))
            // Never the person who counted any of them.
            ->reject(fn (User $u) => $results->contains(fn (StockTakeResult $r) => StockTakeAccess::countedBy($u, $r)))
            ->sortBy('name')
            ->mapWithKeys(fn (User $u) => [(int) $u->id => $u->name])
            ->all();

        return [
            Select::make('assigned_to')
                ->label('Who recounts')
                ->options($options)
                ->required()
                ->helperText('Not anyone who counted it. The recount is blind too.'),
            DatePicker::make('due_date')->label('Due')->default(\App\Support\Pos\BusinessDate::today())->native(false),
        ];
    }

    private function sendToRecount(Collection $results, array $data): void
    {
        $this->attempt(fn () => app(StockTakeApprover::class)->sendToRecount(
            auth()->user(),
            $results,
            User::findOrFail($data['assigned_to']),
            $data['due_date'] ?? null,
        ), 'Sent to recount');
    }

    private function aislesOf(StockTakeResult $r): string
    {
        return StockTakeEntry::query()
            ->where('stock_take_task_id', $r->stock_take_task_id)
            ->where('product_id', $r->product_id)
            ->with('aisle')
            ->get()
            ->map(fn (StockTakeEntry $e) => $e->aisle?->label())
            ->filter()
            ->unique()
            ->implode(', ');
    }

    /** "3 Crate + 5 Piece", summed over the product's entries in the task. */
    private function breakdownOf(StockTakeResult $r): ?string
    {
        $lines = StockTakeEntry::query()
            ->where('stock_take_task_id', $r->stock_take_task_id)
            ->where('product_id', $r->product_id)
            ->get()
            ->flatMap(fn (StockTakeEntry $e) => $e->entry_breakdown ?? [])
            ->groupBy('unit')
            ->map(fn ($group, $unit) => Quantity::format(Quantity::add(...$group->pluck('qty')->all())) . " {$unit}");

        return $lines->count() > 1 ? $lines->implode(' + ') : null;
    }

    /** The other count of a recount pair, side by side. */
    private function pairLine(StockTakeResult $r, bool $money): ?string
    {
        $other = $r->recountOf ?? $r->recountResult;

        if (! $other) {
            return null;
        }

        $label = $r->recountOf ? 'Original' : 'Recount';
        $value = $money && self::valueOf($other) !== null ? ', ₦' . number_format(self::valueOf($other), 2) : '';

        return sprintf(
            '%s by %s: %s (system %s, %s%s%s)',
            $label,
            $other->task?->assignee?->name ?? '—',
            Quantity::format($other->total_counted_base),
            Quantity::format($other->system_qty_at_count),
            $other->variance_qty > 0 ? '+' : '',
            Quantity::format($other->variance_qty),
            $value,
        );
    }

    /** @return array<int, string> */
    private function flagsOf(StockTakeResult $r): array
    {
        $entries = StockTakeEntry::query()
            ->where('stock_take_task_id', $r->stock_take_task_id)
            ->where('product_id', $r->product_id)
            ->get(['is_unlisted', 'is_not_on_shelf']);

        return array_values(array_filter([
            $r->needs_recount ? 'Recount needed' : null,
            $r->recount_of_id ? 'Recount' : null,
            $entries->contains('is_unlisted', true) ? 'Not on aisle list' : null,
            $entries->contains('is_not_on_shelf', true) ? 'Not on shelf' : null,
            $r->recheck_needed_at !== null ? 'Late till sale — not yet corrected' : null,
        ]));
    }

    private function attempt(callable $call, string $success): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            Notification::make()->title('Not done')->body(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
        $this->dispatch('stock-take-updated');
    }
}
