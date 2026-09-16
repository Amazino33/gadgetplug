<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Actions\Cash\GenerateSettlementStatementAction;
use App\Actions\Inventory\ApproveStockCountAction;
use App\Actions\Inventory\RecordPhysicalCountAction;
use App\Models\PhysicalStockCount;
use App\Models\Product;
use App\Models\SettlementResolution;
use App\Models\Store;
use App\Models\StoreSettlementStatement;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\Auth\StorePermission;
use App\Services\Cash\StoreReconciliation;
use App\Services\Reporting\ReportPeriod;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

/**
 * Checkmate: what should have come back from this branch, against what did.
 *
 * Recalculated live for whatever window is picked, so the page always answers
 * for the period in front of you rather than a fixed cycle somebody has to
 * remember to close. Freezing a copy is a separate, deliberate act — the button
 * at the top — because a snapshot is only worth anything if somebody chose to
 * take it and their name is on it.
 */
class StoreSettlement extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-scale';

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Store Settlement';

    protected static ?string $title = 'Store Settlement';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.vendor.pages.store-settlement';

    /** @var array<string, mixed> */
    public ?array $filters = [];

    /**
     * Who may open this page at all.
     *
     * Gated separately from the permissions that act on it, and deliberately
     * tighter: the page carries revenue, cost of goods, margin and the branch's
     * whole standing position. A cashier who may hand cash over — or count a
     * shelf — has no business reading what the business makes on each sale.
     *
     * Same shape as FinancialReport and the other report pages.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_store_settlement')
        );
    }

    /** Never in the sidebar for somebody who cannot open it. */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill(['preset' => 'this_month']);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
                        Select::make('preset')
                            ->label('Period')
                            ->options(ReportPeriod::PRESETS)
                            ->default('this_month')
                            ->selectablePlaceholder(false)
                            ->live(),

                        DatePicker::make('from')
                            ->label('From')
                            ->maxDate(now())
                            ->live()
                            ->visible(fn (Get $get): bool => $get('preset') === 'custom'),

                        DatePicker::make('to')
                            ->label('To')
                            ->maxDate(now())
                            ->live()
                            ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
                    ])
                    ->columns(['sm' => 2, 'lg' => 3]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // One person, one branch, at settlement. Deliberately lighter than
            // the blind count audit, which is a two-person anti-collusion
            // exercise across the whole business — a different job at a
            // different cadence. What they must never do is disagree about who
            // owes what, which is why a shortage found here is posted to the
            // same accountability ledger the audit uses.
            Action::make('countStock')
                ->label('Count stock')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn () => $this->canSettle() && $this->store() !== null)
                ->modalHeading('Count what is actually on the shelf')
                ->modalDescription('Enter what you physically counted. Anything missing is valued at what it would have sold for, so it can be read beside the cash.')
                ->modalSubmitActionLabel('Record the count')
                ->schema(fn () => [
                    Repeater::make('lines')
                        ->label('')
                        ->schema([
                            Select::make('product_id')
                                ->label('Product')
                                ->options(fn () => $this->countableProducts())
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
                ->action(function (array $data) {
                    $store = $this->store();
                    $period = $this->period();

                    $counts = collect($data['lines'] ?? [])
                        ->filter(fn ($line) => filled($line['product_id'] ?? null))
                        ->mapWithKeys(fn ($line) => [(int) $line['product_id'] => (int) $line['counted']])
                        ->all();

                    if ($counts === []) {
                        Notification::make()->title('Nothing was counted.')->danger()->send();

                        return;
                    }

                    try {
                        $count = app(RecordPhysicalCountAction::class)->execute(
                            countedBy:   auth()->user(),
                            store:       $store,
                            periodStart: $period->from,
                            periodEnd:   $period->to,
                            counts:      $counts,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    $variance = $count->variance();

                    Notification::make()
                        ->title($variance['units'] === 0
                            ? 'Counted — everything adds up'
                            : $variance['units'] . ' unit(s) unaccounted for')
                        ->body($variance['units'] === 0
                            ? 'The shelf matches the books.'
                            : 'Worth ₦' . number_format($variance['missing_at_selling'], 2) . ' at selling price.')
                        ->{$variance['units'] === 0 ? 'success' : 'warning'}()
                        ->send();
                }),

            // Sits beside the count rather than on a separate screen: the person
            // signing it off is looking at the same page that shows the gap and
            // the cash beside it, which is the judgement they are making.
            Action::make('approveCount')
                ->label('Approve count')
                ->icon('heroicon-o-check-badge')
                ->color('warning')
                ->visible(fn () => ($count = $this->stockCount()) !== null
                    && $count->isSubmitted()
                    && $this->canApproveCount($count))
                ->modalHeading(fn () => 'Sign off the stock count')
                ->modalDescription(fn () => ($c = $this->stockCount())
                    ? sprintf(
                        '%d unit(s) unaccounted for, worth %s at selling price. Approving corrects the stock — say where the loss goes.',
                        $c->variance()['units'],
                        '₦' . number_format($c->variance()['missing_at_selling'], 2),
                    )
                    : null)
                ->schema([
                    Radio::make('outcome')
                        ->label('What happened to the missing stock?')
                        ->options([
                            PhysicalStockCount::OUTCOME_WRITTEN_OFF => 'Write it off — the business absorbs it',
                            PhysicalStockCount::OUTCOME_CHARGED     => 'Charge it to somebody',
                        ])
                        ->default(PhysicalStockCount::OUTCOME_WRITTEN_OFF)
                        ->required()
                        ->live(),

                    Select::make('charged_to')
                        ->label('Charge it to')
                        ->options(fn () => $this->chargeableStaff())
                        ->searchable()
                        ->visible(fn (Get $get) => $get('outcome') === PhysicalStockCount::OUTCOME_CHARGED)
                        ->required(fn (Get $get) => $get('outcome') === PhysicalStockCount::OUTCOME_CHARGED)
                        ->helperText('They will owe it at selling price, on the same ledger an audit shortage uses.'),

                    Textarea::make('note')
                        ->label('What was agreed')
                        ->rows(2)
                        ->placeholder('e.g. Two damaged in transit, one unaccounted for'),
                ])
                ->action(function (array $data) {
                    $count = $this->stockCount();

                    if (! $count) {
                        Notification::make()->title('Nothing to approve.')->danger()->send();

                        return;
                    }

                    try {
                        app(ApproveStockCountAction::class)->approve(
                            count:    $count,
                            approver: auth()->user(),
                            outcome:  $data['outcome'],
                            chargeTo: filled($data['charged_to'] ?? null)
                                ? User::find($data['charged_to'])
                                : null,
                            note:     $data['note'] ?? null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Count approved — stock corrected')
                        ->body($data['outcome'] === PhysicalStockCount::OUTCOME_CHARGED
                            ? 'The shortage is now owed by the person named.'
                            : 'The shortage has been written off against the business.')
                        ->success()
                        ->send();
                }),

            // The other half of approving. A manager who does not believe a
            // count needs somewhere to say so — without this their only options
            // were to accept figures they doubt or leave them hanging.
            Action::make('rejectCount')
                ->label('Ask for a recount')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => ($count = $this->stockCount()) !== null
                    && $count->isSubmitted()
                    && $this->canApproveCount($count))
                ->modalHeading('Send it back for a recount')
                ->modalDescription('The figures stay on the record — a count somebody questioned is evidence too. No stock is changed.')
                ->schema([
                    Textarea::make('note')
                        ->label('Why it is not accepted')
                        ->rows(2)
                        ->required()
                        ->placeholder('e.g. Count the back store as well, with me present'),
                ])
                ->action(function (array $data) {
                    $count = $this->stockCount();

                    try {
                        app(ApproveStockCountAction::class)->reject($count, auth()->user(), $data['note']);
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Sent back for a recount')->success()->send();
                }),

            // What was decided at the settlement, kept beside the frozen figures
            // rather than written into them. The statement had a signoff section
            // with no way to fill it in.
            Action::make('recordResolution')
                ->label('Record what was agreed')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn () => $this->canSettle() && $this->statements()->isNotEmpty())
                ->modalHeading('Record a settlement decision')
                ->schema([
                    Select::make('statement_id')
                        ->label('Against which statement')
                        ->options(fn () => $this->statements()
                            ->mapWithKeys(fn ($s) => [
                                $s->id => $s->reference.' — '.$s->period_start->format('d M').' to '.$s->period_end->format('d M Y'),
                            ])->all())
                        ->required()
                        ->default(fn () => $this->statements()->first()?->id),

                    Select::make('concerns')
                        ->label('What it is about')
                        ->options([
                            'true_shortage' => 'The unexplained shortage',
                            'disputed'      => 'A disputed handover',
                            'unsubmitted'   => 'Cash not handed over',
                            'stock'         => 'Missing stock',
                            'unpaid_debt'   => 'Customer debt',
                        ])
                        ->required(),

                    Select::make('outcome')
                        ->label('What was decided')
                        ->options([
                            SettlementResolution::OUTCOME_REPAYMENT_PLAN     => 'Repayment plan agreed',
                            SettlementResolution::OUTCOME_WRITE_OFF_REFERRAL => 'Referred for write-off',
                            SettlementResolution::OUTCOME_RESOLVED_NO_ISSUE  => 'Looked into it — no issue',
                            SettlementResolution::OUTCOME_RECOUNT            => 'Count or check it again',
                            SettlementResolution::OUTCOME_OTHER              => 'Something else',
                        ])
                        ->required(),

                    TextInput::make('amount')
                        ->label('Amount it concerns')
                        ->numeric()
                        ->prefix('₦')
                        ->helperText('Leave blank if it is not about a specific figure.'),

                    Textarea::make('note')
                        ->label('What was agreed')
                        ->rows(3)
                        ->required()
                        ->placeholder('e.g. Ngozi to repay 25,000 a week for four weeks, starting Monday'),
                ])
                ->action(function (array $data) {
                    try {
                        SettlementResolution::create([
                            'store_settlement_statement_id' => $data['statement_id'],
                            'recorded_by' => auth()->id(),
                            'outcome'     => $data['outcome'],
                            'concerns'    => $data['concerns'],
                            'amount'      => filled($data['amount'] ?? null) ? (float) $data['amount'] : null,
                            'note'        => $data['note'],
                        ]);
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Recorded')
                        ->body('It now appears on that statement, and the figures stay as they were.')
                        ->success()
                        ->send();
                }),

            Action::make('generate')
                ->label('Freeze this statement')
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->visible(fn () => $this->canSettle())
                ->requiresConfirmation()
                ->modalHeading('Freeze these figures')
                ->modalDescription('Takes a copy of the numbers exactly as they stand now, with your name and the time on it. Later corrections will not change this copy.')
                ->action(function () {
                    $store = $this->store();

                    if (! $store) {
                        Notification::make()->title('No branch selected.')->danger()->send();

                        return;
                    }

                    $period = $this->period();

                    try {
                        $statement = app(GenerateSettlementStatementAction::class)->execute(
                            generatedBy: auth()->user(),
                            store:       $store,
                            from:        $period->from,
                            to:          $period->to,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title($statement->reference . ' frozen')
                        ->body('Open it to print or sign off.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'period'     => $this->period(),
            'store'      => $this->store(),
            'recon'      => $this->reconciliation(),
            'branches'   => $this->branches(),
            'stockCount' => $this->stockCount(),
            'statements' => $this->statements(),
        ];
    }

    /**
     * Products this branch actually holds, for the count form.
     *
     * Scoped to stock rows at this store rather than the whole catalogue: a
     * count sheet listing every product the business has ever sold is not one
     * anybody will fill in.
     *
     * @return array<int, string>
     */
    public function countableProducts(): array
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

    /** The most recent count covering this period, if one was taken. */
    public function stockCount(): ?PhysicalStockCount
    {
        $store = $this->store();

        if (! $store) {
            return null;
        }

        $period = $this->period();

        return PhysicalStockCount::query()
            ->where('store_id', $store->id)
            ->whereBetween('counted_at', [$period->from, $period->to])
            ->with('lines.product')
            ->latest('counted_at')
            ->first();
    }

    /** Whether the signed-in person may sign off this particular count. */
    public function canApproveCount(PhysicalStockCount $count): bool
    {
        // Never the counter, whatever else they hold.
        if ((int) $count->counted_by === auth()->id()) {
            return false;
        }

        return StorePermission::allows(
            auth()->user(),
            (int) $count->vendor_id,
            (int) $count->store_id,
            'approve_stock_count',
        );
    }

    /**
     * Who a shortage may be charged to: people who actually work this branch.
     *
     * @return array<int, string>
     */
    public function chargeableStaff(): array
    {
        $store = $this->store();
        $vendor = filament()->getTenant();

        if (! $store || ! $vendor) {
            return [];
        }

        return $vendor->users()
            ->get()
            ->filter(fn (User $user) => $user->storesForVendor($vendor->id)
                ->contains(fn ($s) => (int) $s->id === (int) $store->id))
            ->pluck('name', 'id')
            ->all();
    }

    /** Every branch, with the cashiers holding cash at each. */
    public function branches(): Collection
    {
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return collect();
        }

        $period = $this->period();

        return app(StoreReconciliation::class)->byBranch($vendor, $period->from, $period->to);
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::fromFilters($this->filters);
    }

    public function store(): ?Store
    {
        $vendor = filament()->getTenant();

        $storeId = ActiveStore::currentId() ?? $vendor?->defaultStore?->id;

        return $storeId ? Store::find($storeId) : null;
    }

    /** The live figures for whatever window is picked. */
    public function reconciliation(): ?array
    {
        $store = $this->store();

        if (! $store) {
            return null;
        }

        $period = $this->period();

        return app(StoreReconciliation::class)->forStore($store, $period->from, $period->to);
    }

    /** Copies already frozen for this branch, most recent first. */
    public function statements()
    {
        $store = $this->store();

        return $store
            ? StoreSettlementStatement::where('store_id', $store->id)
                ->with('generatedBy')
                ->latest('generated_at')
                ->limit(10)
                ->get()
            : collect();
    }

    /**
     * Freezing a statement is the receiving side of the arrangement, so it is
     * gated on the same permission as taking the money — not on whoever can
     * merely look at the branch.
     */
    private function canSettle(): bool
    {
        $vendor = filament()->getTenant();

        return $vendor && auth()->user()->hasVendorPermission($vendor->id, 'receive_cash');
    }
}
