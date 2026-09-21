<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Actions\Cash\CloseStoreAccountAction;
use App\Actions\Inventory\RecordPhysicalCountAction;
use App\Models\PhysicalStockCount;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreAccountClose;
use App\Services\ActiveStore;
use App\Services\Auth\StorePermission;
use App\Services\Cash\StoreAccountCloseBalance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

/**
 * Closing the books on a branch for a period.
 *
 * Sits on top of Checkmate rather than replacing it. The settlement page stays
 * live and re-runnable over any window somebody cares to pick; this ends a
 * period, and it is the only screen in the system that does.
 *
 * Two things happen when the button is pressed and nothing else: the figures
 * are frozen with a name on them, and the closing count becomes the next
 * period's opening. Completed sales are untouched — a correction dated inside a
 * closed period still lands, it simply will not move the copy somebody signed.
 */
class AccountClose extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Account Close';

    protected static ?string $title = 'Account Close';

    // Immediately after Store Settlement, which is the page this one closes.
    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.vendor.pages.account-close';

    /** @var array<string, mixed> */
    public ?array $filters = [];

    /**
     * Recomputed per render and keyed on the filters that produced it, so
     * switching branch or period can never leave last selection's totals on
     * screen. Cheaper than recomputing for each of the half-dozen callers
     * within one render, and safe because the key changes when they do.
     *
     * @var array{key: string, value: array<string, mixed>}|null
     */
    private ?array $memo = null;

    /**
     * Who may open this at all.
     *
     * The page carries the branch's whole standing position, so reading it is
     * gated the same way the settlement it sits on is. Somebody granted only
     * the power to close still gets in — otherwise the permission would be
     * unusable without also handing over the reporting view.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_store_settlement') ||
            $user->hasVendorPermission($vendor->id, 'close_store_period')
        );
    }

    /** Never in the sidebar for somebody who cannot open it. */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'store_id' => ActiveStore::currentId() ?? filament()->getTenant()?->defaultStore?->id,
            'to'       => now()->toDateString(),
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
                        Select::make('store_id')
                            ->label('Branch')
                            ->options(fn (): array => $this->branchOptions())
                            ->selectablePlaceholder(false)
                            ->live(),

                        DatePicker::make('from')
                            ->label('Period from')
                            ->live()
                            // Fixed by the chain once a branch has been closed
                            // before: this period starts where the last one
                            // ended, and a gap between them is stock and cash
                            // nobody ever accounted for.
                            ->disabled(fn (): bool => $this->priorClose() !== null)
                            ->dehydrated()
                            ->helperText(fn (): ?string => ($prior = $this->priorClose())
                                ? 'Follows on from ' . $prior->reference . ', which closed ' . $prior->period_to->format('d M Y') . '.'
                                : 'This branch has never been closed, so the first period starts wherever you say.'),

                        DatePicker::make('to')
                            ->label('Period to')
                            ->maxDate(now())
                            ->live(),
                    ])
                    ->columns(['sm' => 1, 'lg' => 3]),

                Section::make('Stock')
                    ->description(fn (): string => $this->stockNote())
                    ->schema([
                        Select::make('opening_count_id')
                            ->label('Opening count')
                            ->options(fn (): array => $this->countOptions())
                            ->searchable()
                            ->live()
                            // Only ever a choice on a first close. After that
                            // the previous closing count carries forward, and
                            // an opening somebody can pick is an opening
                            // somebody can pick to suit the answer.
                            ->visible(fn (): bool => $this->priorClose() === null)
                            ->helperText('Which count the period opens on. Pick one already recorded, or take a fresh one with the button above.'),

                        Select::make('closing_count_id')
                            ->label('Closing count')
                            ->options(fn (): array => $this->countOptions(excludeUsed: true))
                            ->searchable()
                            ->live()
                            ->helperText('What was on the shelf at the end. This becomes the next period\'s opening.'),
                    ])
                    ->columns(['sm' => 1, 'lg' => 2]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('countStock')
                ->label('Count stock')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn (): bool => $this->store() !== null && $this->canCount())
                ->modalHeading('Count what is actually on the shelf')
                ->modalDescription('Enter what you physically counted. The system figure is frozen beside it, so the two being compared were true at the same moment.')
                ->modalSubmitActionLabel('Record the count')
                ->schema(fn (): array => [
                    Repeater::make('lines')
                        ->label('')
                        ->schema([
                            Select::make('product_id')
                                ->label('Product')
                                ->options(fn (): array => $this->countableProducts())
                                ->searchable()
                                ->required()
                                ->distinct(),

                            TextInput::make('counted')
                                ->label('Counted')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->addActionLabel('Add another product')
                        ->reorderable(false),
                ])
                ->action(fn (array $data) => $this->recordCount($data)),

            Action::make('close')
                ->label('Close period')
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->visible(fn (): bool => $this->store() !== null && $this->canClose())
                ->requiresConfirmation()
                ->modalHeading('Close the books for this period')
                ->modalDescription(fn (): string => $this->closeWarning())
                ->modalSubmitActionLabel('Close the period')
                ->action(fn () => $this->closePeriod()),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'store'       => $this->store(),
            'period'      => $this->period(),
            'view'        => $this->balance(),
            'priorClose'  => $this->priorClose(),
            'closingCount' => $this->closingCount(),
            'closes'      => $this->closes(),
            'canClose'    => $this->canClose(),
        ];
    }

    // ---------------------------------------------------------------- state

    public function store(): ?Store
    {
        $vendor = filament()->getTenant();
        $storeId = (int) ($this->filters['store_id'] ?? 0)
            ?: (ActiveStore::currentId() ?? $vendor?->defaultStore?->id);

        if (! $storeId || ! $vendor) {
            return null;
        }

        // Scoped to the tenant rather than found by id alone: a store id typed
        // into the filter state must not reach another business's branch.
        return Store::where('vendor_id', $vendor->id)->find($storeId);
    }

    /**
     * The window being closed.
     *
     * Starts where the last close ended, when there was one. A first close
     * starts wherever the user says, falling back to the start of the month so
     * the page renders something sane before anybody has chosen.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public function period(): array
    {
        $prior = $this->priorClose();

        $from = $prior
            ? Carbon::parse($prior->period_to)
            : (filled($this->filters['from'] ?? null)
                ? Carbon::parse($this->filters['from'])->startOfDay()
                : now()->startOfMonth());

        $to = filled($this->filters['to'] ?? null)
            ? Carbon::parse($this->filters['to'])->endOfDay()
            : now();

        return ['from' => $from, 'to' => $to];
    }

    public function priorClose(): ?StoreAccountClose
    {
        $store = $this->store();

        return $store ? StoreAccountClose::latestFor($store->id) : null;
    }

    /**
     * The live two-sided balance for whatever is picked.
     *
     * @return array<string, mixed>|null
     */
    public function balance(): ?array
    {
        $store = $this->store();

        if (! $store) {
            return null;
        }

        $period = $this->period();
        $closing = $this->closingCount();
        $opening = $this->openingCount();

        $key = implode('|', [
            $store->id,
            $period['from']->toDateTimeString(),
            $period['to']->toDateTimeString(),
            $closing?->id ?? '-',
            $opening?->id ?? '-',
        ]);

        if ($this->memo && $this->memo['key'] === $key) {
            return $this->memo['value'];
        }

        try {
            $value = app(StoreAccountCloseBalance::class)
                ->for($store, $period['from'], $period['to'], $closing, $opening);
        } catch (Throwable $e) {
            // A chosen opening that the chain will not accept, most often.
            // Reported rather than fatal: the page still has a money side worth
            // looking at, and the message says what to fix.
            Notification::make()->title($e->getMessage())->warning()->send();

            $value = app(StoreAccountCloseBalance::class)
                ->for($store, $period['from'], $period['to'], $closing);
        }

        $this->memo = ['key' => $key, 'value' => $value];

        return $value;
    }

    public function closingCount(): ?PhysicalStockCount
    {
        return $this->countById($this->filters['closing_count_id'] ?? null);
    }

    public function openingCount(): ?PhysicalStockCount
    {
        // Ignored outright once a prior close exists — the chain owns it, and
        // leaving a stale selection in the filter state must not quietly
        // override what the last period closed on.
        if ($this->priorClose()) {
            return null;
        }

        return $this->countById($this->filters['opening_count_id'] ?? null);
    }

    /** Closes already made at this branch, most recent first. */
    public function closes(): Collection
    {
        $store = $this->store();

        return $store
            ? StoreAccountClose::forStore($store->id)
                ->with('closedBy')
                ->orderByDesc('period_to')
                ->limit(10)
                ->get()
            : collect();
    }

    // ---------------------------------------------------------- permissions

    public function canClose(): bool
    {
        $store = $this->store();

        return $store !== null && StorePermission::allows(
            auth()->user(),
            (int) $store->vendor_id,
            (int) $store->id,
            'close_store_period',
        );
    }

    public function canCount(): bool
    {
        $store = $this->store();

        return $store !== null && StorePermission::allows(
            auth()->user(),
            (int) $store->vendor_id,
            (int) $store->id,
            'count_stock',
        );
    }

    // -------------------------------------------------------------- actions

    private function recordCount(array $data): void
    {
        $store = $this->store();
        $period = $this->period();

        $counts = collect($data['lines'] ?? [])
            ->filter(fn ($line) => filled($line['product_id'] ?? null))
            ->mapWithKeys(fn ($line) => [(int) $line['product_id'] => (int) $line['counted']])
            ->all();

        if (! $store || $counts === []) {
            Notification::make()->title('Nothing was counted.')->danger()->send();

            return;
        }

        try {
            $count = app(RecordPhysicalCountAction::class)->execute(
                countedBy:   auth()->user(),
                store:       $store,
                periodStart: $period['from'],
                periodEnd:   $period['to'],
                counts:      $counts,
            );
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        // Selected straight away, because the only reason to take a count from
        // this page is to close on it.
        $this->filters['closing_count_id'] = $count->id;
        $this->memo = null;

        Notification::make()
            ->title('Count recorded')
            ->body('It is now selected as the closing count for this period.')
            ->success()
            ->send();
    }

    private function closePeriod(): void
    {
        $store = $this->store();
        $closing = $this->closingCount();

        if (! $store) {
            Notification::make()->title('No branch selected.')->danger()->send();

            return;
        }

        // Guarded rather than merely hidden. A period closed without counting
        // the shelf has checked only the side that balances when goods walk out
        // unrecorded.
        if (! $closing) {
            Notification::make()
                ->title('Pick a closing count first')
                ->body('Closing needs a count of what is actually on the shelf. Take one with the button above if there is not one yet.')
                ->danger()
                ->send();

            return;
        }

        $period = $this->period();

        try {
            $close = app(CloseStoreAccountAction::class)->execute(
                closedBy:     auth()->user(),
                store:        $store,
                from:         $period['from'],
                to:           $period['to'],
                closingCount: $closing,
                figures:      $this->balance() ?? [],
                openingCount: $this->openingCount(),
            );
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        // Cleared so the page reads as the NEXT open period rather than showing
        // the one just closed with its counts still selected.
        $this->filters['closing_count_id'] = null;
        $this->filters['opening_count_id'] = null;
        $this->memo = null;

        Notification::make()
            ->title($close->reference . ' closed')
            ->body('The figures are frozen, and that closing count is now the next period\'s opening.')
            ->success()
            ->send();
    }

    /**
     * What the person pressing the button ought to know first.
     *
     * Warns, never blocks. Money waiting on a receiver is nobody's problem yet,
     * but closing a period without being told it is out there is how one gets
     * signed off blind.
     */
    private function closeWarning(): string
    {
        $view = $this->balance();
        $subs = $view['submissions'] ?? [];

        $lines = ['Freezes these figures with your name on them, and carries the closing count forward as the next period\'s opening. Completed sales are not locked or changed.'];

        if ((float) ($subs['pending'] ?? 0) > 0.009) {
            $lines[] = sprintf(
                '₦%s has been handed over and is still waiting on a receiver. It is not counted as accounted for, so it is sitting inside the shortage.',
                number_format((float) $subs['pending'], 2),
            );
        }

        if ((float) ($subs['disputed'] ?? 0) > 0.009) {
            $lines[] = sprintf(
                '₦%s is disputed between two people and not yet settled.',
                number_format((float) $subs['disputed'], 2),
            );
        }

        if (! ($view['checkmate']['agrees'] ?? true)) {
            $lines[] = 'The two sides do not reduce to the cash gap. Something upstream is not what this arithmetic assumes — worth understanding before this is put to anybody.';
        }

        return implode(' ', $lines);
    }

    // -------------------------------------------------------------- options

    /**
     * What this period covers and what came into it, said where the counts are
     * picked rather than only further down the page.
     *
     * Somebody choosing a count is deciding whether it matches the period in
     * front of them, and they cannot do that without being told what the period
     * is. The deliveries are here for the same reason: a branch that took stock
     * in this month and sees nothing about it concludes the screen lost it.
     */
    private function stockNote(): string
    {
        $plain = 'Counting the shelf is the only check that sees goods leaving without a sale being rung.';

        if (! $this->store()) {
            return $plain;
        }

        $period = $this->period();
        $received = $this->balance()['procurement'] ?? ['units_received' => 0, 'batches' => 0];

        return sprintf(
            'Closing %s to %s. %d units came in on %d %s in this period. %s',
            $period['from']->format('d M Y'),
            $period['to']->format('d M Y'),
            $received['units_received'],
            $received['batches'],
            $received['batches'] === 1 ? 'delivery' : 'deliveries',
            $plain,
        );
    }

    /** @return array<int, string> */
    private function branchOptions(): array
    {
        $vendor = filament()->getTenant();

        return $vendor
            ? $vendor->stores()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()
            : [];
    }

    /**
     * Counts taken at this branch, newest first.
     *
     * @param  bool  $excludeUsed  Drops any count that has already closed a
     *   period. Reusing one would carry the same opening forward twice and hide
     *   a whole period of movement.
     * @return array<int, string>
     */
    private function countOptions(bool $excludeUsed = false): array
    {
        $store = $this->store();

        if (! $store) {
            return [];
        }

        return PhysicalStockCount::query()
            ->where('store_id', $store->id)
            ->when($excludeUsed, fn ($q) => $q->whereNotIn(
                'id',
                StoreAccountClose::forStore($store->id)->pluck('closing_count_id'),
            ))
            ->with('countedBy:id,name')
            ->withCount('lines')
            ->orderByDesc('counted_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (PhysicalStockCount $c) => [
                // Leads with the period the count covers, because that is what
                // somebody is matching against when they pick one. Counting the
                // shelf on the 20th for the period ending the 15th is a real
                // mistake, and a label showing only the date it was taken hides it.
                $c->id => sprintf(
                    '%s to %s · %d products · counted %s by %s',
                    $c->period_start?->format('d M') ?? '?',
                    $c->period_end?->format('d M Y') ?? '?',
                    $c->lines_count,
                    $c->counted_at?->format('d M Y') ?? 'undated',
                    $c->countedBy?->name ?? 'unknown',
                ),
            ])
            ->all();
    }

    /**
     * Products this branch actually holds.
     *
     * Scoped to stock rows at this store rather than the whole catalogue: a
     * count sheet listing every product the business has ever sold is not one
     * anybody will fill in.
     *
     * @return array<int, string>
     */
    private function countableProducts(): array
    {
        $store = $this->store();

        if (! $store) {
            return [];
        }

        return Product::query()
            ->join('product_store_stock as pss', 'pss.product_id', '=', 'products.id')
            ->where('pss.store_id', $store->id)
            ->orderBy('products.name')
            ->pluck('products.name', 'products.id')
            ->all();
    }

    private function countById(mixed $id): ?PhysicalStockCount
    {
        $store = $this->store();

        if (! $store || blank($id)) {
            return null;
        }

        // Scoped to the branch, so a stale id left in the filter state after
        // switching branch resolves to nothing rather than to another branch's
        // count.
        return PhysicalStockCount::where('store_id', $store->id)->find((int) $id);
    }
}
