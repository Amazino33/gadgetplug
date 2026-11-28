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
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
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
                'recountTask:id,status',
            ]))
            // Biggest problem first: the largest gap in ₦ (in quantity for
            // those who may not see cost), so the important ones are on top.
            ->defaultSort(fn (Builder $query): Builder => $money
                ? $query->orderByRaw('ABS(COALESCE(stock_take_results.variance_value, 0)) DESC')->orderByRaw('ABS(stock_take_results.variance_qty) DESC')->orderByDesc('stock_take_results.id')
                : $query->orderByRaw('ABS(stock_take_results.variance_qty) DESC')->orderByDesc('stock_take_results.id'))
            // Scopes the phone styles in the vendor theme to this table.
            ->extraAttributes(['class' => 'gp-st-results'])
            // The list scrolls in its own box: a fuller page by default.
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Nothing to decide yet')
            ->emptyStateDescription('Results appear here when a counter hands a task in.')
            ->columns([
                // Phone only: one card per count with the figures large and
                // the decisions as big buttons, so an owner can approve from
                // a phone without scrolling sideways. Every column below is
                // visibleFrom('md'); filters, search and actions are the same.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('filament.vendor.stock-take.result-card')
                    ->disabledClick()
                    ->hiddenFrom('md'),

                // Product, with where, who and when underneath: the facts that
                // used to be three more columns off the right of the screen.
                TextColumn::make('product.name')
                    ->visibleFrom('md')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable()
                    ->wrap()
                    ->description(fn (StockTakeResult $r) => collect([
                        $this->aislesOf($r) ?: null,
                        $r->task?->assignee?->name,
                        $r->counted_at?->timezone('Africa/Lagos')->format('D j M, g:ia'),
                    ])->filter()->implode(' · ')),

                // Counted, with the packs it was counted in and anything
                // damaged or expired (on the shelf, written off on approval).
                TextColumn::make('total_counted_base')
                    ->visibleFrom('md')
                    ->label('Counted')
                    ->alignEnd()
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => Quantity::format($state))
                    ->description(fn (StockTakeResult $r) => collect([
                        $this->breakdownOf($r),
                        (float) $r->damaged_qty_base > 0 ? Quantity::format($r->damaged_qty_base) . ' damaged' : null,
                        (float) $r->expired_qty_base > 0 ? Quantity::format($r->expired_qty_base) . ' expired' : null,
                    ])->filter()->implode(' · ') ?: null),

                // What the books said when it was counted. Click: what happened.
                TextColumn::make('system_qty_at_count')
                    ->visibleFrom('md')
                    ->label('Books said')
                    ->alignEnd()
                    ->sortable()
                    ->state(fn (StockTakeResult $r) => $r->applied_system_qty ?? $r->system_qty_at_count)
                    ->formatStateUsing(fn ($state) => Quantity::format($state))
                    ->tooltip('See what happened')
                    ->extraAttributes(fn (StockTakeResult $r) => self::storyTrigger($r)),

                // The gap, and what it is worth (to those who may see cost).
                TextColumn::make('variance_qty')
                    ->visibleFrom('md')
                    ->label('Gap')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('See what happened')
                    ->extraAttributes(fn (StockTakeResult $r) => self::storyTrigger($r))
                    ->state(fn (StockTakeResult $r) => $r->applied_adjustment_qty ?? $r->variance_qty)
                    ->formatStateUsing(fn ($state) => (float) $state == 0 ? '✓' : (($state > 0 ? '+' : '−') . Quantity::format(abs((float) $state))))
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                    ->weight('bold')
                    ->description(function (StockTakeResult $r) use ($money) {
                        $value = $money ? self::valueOf($r) : null;

                        return $value === null || (float) $value == 0.0 ? null : ($value < 0 ? '−' : '+') . '₦' . number_format(abs($value), 2);
                    }),

                // Where it stands, and anything to watch, in one place.
                TextColumn::make('status')
                    ->visibleFrom('md')
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
                    ->description(fn (StockTakeResult $r) => collect([
                        ...$this->flagsOf($r),
                        $this->pairLine($r, $money),
                        $r->approver ? "by {$r->approver->name}" : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->wrap(),
            ])
            ->filters([
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
                    ->iconButton()
                    ->tooltip(fn (StockTakeResult $r) => $r->recount_of_id || $r->status === StockTakeResult::STATUS_SENT_TO_RECOUNT ? 'Approve this count' : 'Approve')
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
                    ->iconButton()
                    ->tooltip('Reject')
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
                    ->iconButton()
                    ->tooltip('Send to recount')
                    ->label('Send to recount')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (StockTakeResult $r) => $r->status === StockTakeResult::STATUS_PENDING
                        && StockTakeAccess::canManage(auth()->user(), (int) $r->vendor_id, (int) $r->store_id))
                    ->schema(fn (StockTakeResult $r) => $this->recountFields(collect([$r])))
                    ->action(fn (StockTakeResult $r, array $data) => $this->sendToRecount(collect([$r]), $data)),

                Action::make('recheck')
                    ->iconButton()
                    ->tooltip('Re-check')
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
                BulkAction::make('approve_selected')
                    ->label('Approve selected')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (?Collection $records) => 'Approve ' . ($records?->count() ?? 0) . ' selected?')
                    ->modalDescription('Each one corrects stock by its gap at the moment of counting, worked out again now: sales made since are kept. Any you counted yourself are left for someone else.')
                    ->modalSubmitActionLabel('Approve')
                    ->action(fn (Collection $records) => $this->decideSelected($records, true))
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('reject_selected')
                    ->label('Reject selected')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (?Collection $records) => 'Reject ' . ($records?->count() ?? 0) . ' selected?')
                    ->modalDescription('Stock is left as it is. The counts stay on record. Any you counted yourself are left for someone else.')
                    ->modalSubmitActionLabel('Reject')
                    ->action(fn (Collection $records) => $this->decideSelected($records, false))
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('recount_selected')
                    ->label('Send selected to recount')
                    ->icon('heroicon-o-arrow-path')
                    ->schema(fn (?Collection $records) => $this->recountFields($records ?? collect()))
                    ->action(fn (Collection $records, array $data) => $this->sendToRecount($records, $data))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * What to look at, one tap away, with how many in each: To decide first.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn () => StockTakeResult::query()->where('stock_take_id', $this->getOwnerRecord()->id);
        $pending = fn (Builder $query) => $query->where('status', StockTakeResult::STATUS_PENDING);

        return [
            'decide' => Tab::make('To decide')
                ->badge(fn () => $pending($base())->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing($pending),
            'recount_needed' => Tab::make('Recount needed')
                ->badge(fn () => $pending($base())->where('needs_recount', true)->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $pending($query)->where('needs_recount', true)),
            'shortages' => Tab::make('Shortages')
                ->badge(fn () => $pending($base())->where('variance_qty', '<', 0)->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $pending($query)->where('variance_qty', '<', 0)),
            'recounting' => Tab::make('Being recounted')
                ->badge(fn () => $base()->where('status', StockTakeResult::STATUS_SENT_TO_RECOUNT)->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StockTakeResult::STATUS_SENT_TO_RECOUNT)),
            'approved' => Tab::make('Approved')
                ->badge(fn () => $base()->where('status', StockTakeResult::STATUS_APPROVED)->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StockTakeResult::STATUS_APPROVED)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StockTakeResult::STATUS_REJECTED)),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'decide';
    }

    /**
     * Makes a number open "what happened" (CountStoryPanel): the panel slides
     * in at once with a loading sign, and only the story goes to the server.
     */
    public static function storyTrigger(StockTakeResult $r): array
    {
        return [
            'class'      => 'gp-st-link cursor-pointer',
            'role'       => 'button',
            'tabindex'   => '0',
            'x-on:click' => "\$dispatch('gp-count-story', { id: {$r->id} })",
            'x-on:keydown.enter' => "\$dispatch('gp-count-story', { id: {$r->id} })",
            // A pointer resting on it (or a finger landing) starts loading,
            // so the story is usually there by the time of the click.
            'x-on:mouseenter' => "\$dispatch('gp-count-story-hover', { id: {$r->id} })",
            'x-on:mouseleave' => "\$dispatch('gp-count-story-hover', { id: null })",
            'x-on:touchstart.passive' => "\$dispatch('gp-count-story-hover', { id: {$r->id} })",
        ];
    }

    /**
     * What the phone card shows for one count (result-card.blade.php): the
     * same facts as the desktop columns, worked out the same way.
     */
    public function cardData(StockTakeResult $r): array
    {
        $money = ProductForm::canSeeCostPrice();

        return [
            'aisles'    => $this->aislesOf($r),
            'breakdown' => $this->breakdownOf($r),
            'flags'     => $this->flagsOf($r),
            'pair'      => $this->pairLine($r, $money),
            'books'     => $r->applied_system_qty ?? $r->system_qty_at_count,
            'gap'       => $r->applied_adjustment_qty ?? $r->variance_qty,
            'value'     => $money ? self::valueOf($r) : null,
        ];
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

    /** @var array<int, bool> result id => may decide it now, for this request's buttons */
    private array $decidableCache = [];

    /**
     * Asked by every decision button, on screen and on the phone card: worked
     * out once a row per request (it looks up who counted it).
     */
    private function decidable(StockTakeResult $r): bool
    {
        return $this->decidableCache[$r->id] ??= $this->workOutDecidable($r);
    }

    private function workOutDecidable(StockTakeResult $r): bool
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

    private function decideSelected(Collection $records, bool $approve): void
    {
        $result = app(StockTakeApprover::class)->decideMany(auth()->user(), $records, $approve);
        $verb   = $approve ? 'approved' : 'rejected';

        Notification::make()
            ->title("{$result['done']} {$verb}")
            ->body($result['left'] > 0 ? "{$result['left']} left: counted by you, or no longer waiting for a decision." : null)
            ->{$result['done'] > 0 ? 'success' : 'warning'}()
            ->send();

        $this->entriesCache = null;
        $this->decidableCache = [];
        $this->dispatch('stock-take-updated');
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

    /** @var array<string, \Illuminate\Support\Collection<int, StockTakeEntry>>|null  "task:product" => entries, for the rows on screen */
    private ?array $entriesCache = null;

    /**
     * The entries behind one row. The first row asked loads them for every
     * row on the page at once (with their aisles), so drawing the list is a
     * handful of queries, not several a row.
     *
     * @return \Illuminate\Support\Collection<int, StockTakeEntry>
     */
    private function entriesOf(StockTakeResult $r): \Illuminate\Support\Collection
    {
        $key = $r->stock_take_task_id . ':' . $r->product_id;

        if ($this->entriesCache === null || ! array_key_exists($key, $this->entriesCache)) {
            $page = $this->getTableRecords();
            $rows  = collect($page instanceof \Illuminate\Contracts\Pagination\Paginator ? $page->items() : $page)->push($r);

            $this->entriesCache = ($this->entriesCache ?? []) + StockTakeEntry::query()
                ->whereIn('stock_take_task_id', $rows->pluck('stock_take_task_id')->unique()->all())
                ->whereIn('product_id', $rows->pluck('product_id')->unique()->all())
                ->with('aisle')
                ->get()
                ->groupBy(fn (StockTakeEntry $e) => $e->stock_take_task_id . ':' . $e->product_id)
                ->all();

            foreach ($rows as $row) {
                $this->entriesCache[$row->stock_take_task_id . ':' . $row->product_id] ??= collect();
            }
        }

        return $this->entriesCache[$key];
    }

    private function aislesOf(StockTakeResult $r): string
    {
        return $this->entriesOf($r)
            ->map(fn (StockTakeEntry $e) => $e->aisle?->label())
            ->filter()
            ->unique()
            ->implode(', ');
    }

    /** "3 Crate + 5 Piece", summed over the product's entries in the task. */
    private function breakdownOf(StockTakeResult $r): ?string
    {
        $lines = $this->entriesOf($r)
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
        $entries = $this->entriesOf($r);

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
        $this->entriesCache = null;
        $this->decidableCache = [];
        $this->dispatch('stock-take-updated');
    }
}
