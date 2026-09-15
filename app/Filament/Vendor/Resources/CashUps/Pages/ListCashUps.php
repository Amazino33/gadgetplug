<?php

namespace App\Filament\Vendor\Resources\CashUps\Pages;

use App\Actions\CashUp\ApproveCashUpAction;
use App\Actions\CashUp\RecordRectificationAction;
use App\Filament\Vendor\Resources\CashUps\CashUpResource;
use App\Models\CashUpRectification;
use App\Models\PosSession;
use App\Models\PosSale;
use App\Services\ActiveStore;
use App\Support\Pos\BusinessDate;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;

class ListCashUps extends ListRecords
{
    protected static string $resource = CashUpResource::class;

    /**
     * Says which branch these figures are for, out loud.
     *
     * Without this the same table means two different things depending on a
     * store selector elsewhere on the page, and a manager chasing a shortage
     * would have no way to tell whether they were looking at one counter or all
     * of them.
     */
    public function getSubheading(): ?HtmlString
    {
        $storeId = ActiveStore::currentId();
        $store = $storeId ? \App\Models\Store::find($storeId) : null;
        $vendor = filament()->getTenant();

        if ($store) {
            $scope = '<strong>'.e($store->name).'</strong>';

            // The question a manager actually has is whether they are looking at
            // all of their money or one counter's worth. ActiveStore always
            // resolves a branch, so without saying this the answer is invisible
            // and a shortage at another branch would simply never be looked at.
            $others = $vendor
                ? \App\Models\Store::query()->forVendor($vendor->id)->where('id', '!=', $store->id)->count()
                : 0;

            if ($others > 0) {
                $scope .= ' only — '.$others.' other '
                    .($others === 1 ? 'branch is' : 'branches are').' not included';
            }
        } else {
            $scope = '<strong>the whole business</strong> — every branch';
        }

        $waiting = CashUpResource::getEloquentQuery()
            ->where('status', PosSession::STATUS_PENDING_REVIEW)
            ->count();

        return new HtmlString(
            'Showing End of Day records for '.$scope.'. '
            .($waiting > 0
                ? $waiting.' waiting to be reviewed.'
                : 'Nothing waiting to be reviewed.')
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('business_date', 'desc')
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('business_date')
                            ->date('d M Y')
                            ->weight('bold')
                            ->size('lg'),

                        Tables\Columns\TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                PosSession::STATUS_OPEN            => 'Still open',
                                PosSession::STATUS_PENDING_REVIEW  => 'Waiting for review',
                                PosSession::STATUS_APPROVED        => 'Approved',
                                default                               => $state,
                            })
                            ->color(fn (string $state) => match ($state) {
                                PosSession::STATUS_APPROVED       => 'success',
                                PosSession::STATUS_PENDING_REVIEW => 'warning',
                                default                              => 'gray',
                            }),
                    ]),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('cashier.name')
                            ->icon('heroicon-m-user')
                            ->description(fn (PosSession $r) => $r->terminal_id ? 'Terminal '.$r->terminal_id : null),

                        Tables\Columns\TextColumn::make('store.name')
                            ->icon('heroicon-m-building-storefront')
                            ->visible(fn () => ActiveStore::currentId() === null),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('counted_cash')
                            ->label('Cash Drawer')
                            ->formatStateUsing(fn ($state) => 'Counted: ₦' . number_format((float) $state, 2))
                            ->description(fn (PosSession $r) => $r->expected_cash !== null
                                ? 'Expected ₦'.number_format((float) $r->expected_cash, 2)
                                : null),

                        Tables\Columns\TextColumn::make('cash_variance')
                            ->label('Cash difference')
                            ->formatStateUsing(fn ($state) => 'Cash diff: ₦' . number_format((float) $state, 2))
                            ->weight('bold')
                            ->color(fn ($state) => static::varianceColour((float) $state))
                            ->description(fn (PosSession $r) => $r->countsSubmitted()
                                && abs($r->resolvedCashVariance() - (float) $r->cash_variance) > 0.009
                                    ? 'Still unexplained: ₦'.number_format($r->resolvedCashVariance(), 2)
                                    : null),

                        Tables\Columns\TextColumn::make('terminal_variance')
                            ->label('Terminal difference')
                            ->formatStateUsing(fn ($state) => 'Term diff: ₦' . number_format((float) $state, 2))
                            ->color(fn ($state) => static::varianceColour((float) $state))
                            ->description(fn (PosSession $r) => $r->countsSubmitted()
                                && abs($r->resolvedTerminalVariance() - (float) $r->terminal_variance) > 0.009
                                    ? 'Still unexplained: ₦'.number_format($r->resolvedTerminalVariance(), 2)
                                    : null),
                    ])->space(2)->extraAttributes(['class' => 'bg-gray-50 dark:bg-gray-900 rounded-lg p-4 mt-3 border border-gray-100 dark:border-gray-800']),
                ])->space(3),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        PosSession::STATUS_PENDING_REVIEW => 'Waiting for review',
                        PosSession::STATUS_APPROVED       => 'Approved',
                        PosSession::STATUS_OPEN           => 'Still open',
                    ])
                    ->default(PosSession::STATUS_PENDING_REVIEW),

                Tables\Filters\SelectFilter::make('cashier_id')
                    ->label('Cashier')
                    ->relationship('cashier', 'name')
                    ->searchable(),

                Tables\Filters\Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('business_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('business_date', '<=', $d))),

                Tables\Filters\Filter::make('unexplained')
                    ->label('Still unexplained only')
                    ->query(fn ($query) => $query->whereNotNull('cash_variance')
                        ->where(fn ($q) => $q->where('cash_variance', '!=', 0)
                            ->orWhere('terminal_variance', '!=', 0))),
            ])
            ->recordActions([
                \Filament\Actions\ActionGroup::make([
                    $this->detailsAction(),
                    $this->rectifyAction(),
                ])
                ->label('Review')
                ->button()
                ->outlined()
                ->color('gray')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition('after'),

                $this->approveAction(),
            ])
            ->emptyStateHeading('No End of Day records yet')
            ->emptyStateDescription('When a cashier counts their drawer at the till, the day appears here for review.');
    }

    /** The working behind the figures — what the cashier is actually held to. */
    private function detailsAction(): Action
    {
        return Action::make('details')
            ->label('See working')
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->modalHeading(fn (PosSession $record) => $record->cashier->name.' — '
                .$record->business_date->format('d M Y'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->visible(fn (PosSession $record) => $record->countsSubmitted())
            ->authorize(fn (PosSession $record) => auth()->user()->can('view', $record))
            ->modalContent(fn (PosSession $record) => view(
                'filament.vendor.cash-up-breakdown',
                ['session' => $record->load('rectifications.creator', 'rectifications.relatedSale')],
            ));
    }

    /** Accounting for part of a difference. */
    private function rectifyAction(): Action
    {
        return Action::make('rectify')
            ->label('Explain')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (PosSession $record) => $record->acceptsRectifications())
            // The real gate. ->visible() only hides a button.
            ->authorize(fn (PosSession $record) => auth()->user()->can('rectify', $record))
            ->modalHeading('What accounts for this difference?')
            ->modalDescription(fn (PosSession $record) => $this->gapSummary($record))
            ->schema([
                Select::make('kind')
                    ->label('What happened')
                    ->options([
                        CashUpRectification::KIND_EXPENSE        => 'Money was spent out of the drawer',
                        CashUpRectification::KIND_CASH_OUT       => 'Cash was handed to someone',
                        CashUpRectification::KIND_TENDER_RECLASS => 'A sale was rung on the wrong tender',
                        CashUpRectification::KIND_DEBT_PAID      => 'A credit sale was actually paid',
                    ])
                    ->required()
                    ->live(),

                TextInput::make('amount')
                    ->label('How much')
                    ->numeric()
                    ->required()
                    ->prefix('₦')
                    ->minValue(0.01),

                Select::make('from_tender')
                    ->label('Rung as')
                    ->options(['cash' => 'Cash', 'card' => 'Card', 'bank_transfer' => 'Transfer'])
                    ->required()
                    ->visible(fn (Get $get) => $get('kind') === CashUpRectification::KIND_TENDER_RECLASS),

                Select::make('to_tender')
                    ->label('Actually paid on')
                    ->options(['cash' => 'Cash', 'card' => 'Card', 'bank_transfer' => 'Transfer'])
                    ->required()
                    ->visible(fn (Get $get) => in_array($get('kind'), [
                        CashUpRectification::KIND_TENDER_RECLASS,
                        CashUpRectification::KIND_DEBT_PAID,
                    ], true)),

                Select::make('related_sale_id')
                    ->label('Which sale')
                    ->options(fn (PosSession $record, Get $get) => $this->saleOptions($record, $get('kind')))
                    ->searchable()
                    ->required()
                    ->helperText('The sale is flagged for correction. It is never rewritten — it keeps saying what it was rung as.')
                    ->visible(fn (Get $get) => in_array($get('kind'), [
                        CashUpRectification::KIND_TENDER_RECLASS,
                        CashUpRectification::KIND_DEBT_PAID,
                    ], true)),

                Textarea::make('note')
                    ->label('Note')
                    ->rows(2)
                    ->helperText('What the cashier said, or what you found.'),
            ])
            ->action(function (PosSession $record, array $data) {
                try {
                    app(RecordRectificationAction::class)->execute(
                        session: $record,
                        manager: auth()->user(),
                        kind: $data['kind'],
                        amount: (float) $data['amount'],
                        fromTender: $data['from_tender'] ?? null,
                        toTender: $data['to_tender'] ?? null,
                        relatedSaleId: isset($data['related_sale_id']) ? (int) $data['related_sale_id'] : null,
                        note: $data['note'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $record->refresh()->load('rectifications');

                Notification::make()
                    ->title('Recorded')
                    ->body($record->isFullyExplained()
                        ? 'The whole difference is now accounted for.'
                        : 'Still unexplained: ₦'.number_format($record->netResolvedVariance(), 2))
                    ->success()
                    ->send();
            });
    }

    /** Signing the day off, which is what makes the remaining difference real. */
    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->button()
            ->visible(fn (PosSession $record) => $record->isPendingReview())
            ->authorize(fn (PosSession $record) => auth()->user()->can('approve', $record))
            ->requiresConfirmation()
            ->modalHeading('Approve this record?')
            ->modalDescription(fn (PosSession $record) => new HtmlString($this->approvalWarning($record)))
            ->schema([
                Textarea::make('notes')->label('Anything to add')->rows(2),
            ])
            ->action(function (PosSession $record, array $data) {
                try {
                    app(ApproveCashUpAction::class)->execute(
                        $record,
                        auth()->user(),
                        filled($data['notes'] ?? null) ? $data['notes'] : null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Approved')->success()->send();
            });
    }

    /**
     * What the manager is about to make real, said plainly before they do it.
     *
     * The wrong-tender hint is the valuable half: two legs out by the same
     * amount in opposite directions is nearly always one sale rung on the wrong
     * button, and approving it as-is would charge a cashier for money sitting on
     * the terminal.
     */
    private function approvalWarning(PosSession $record): string
    {
        $record->loadMissing('rectifications');
        $cash = $record->resolvedCashVariance();

        if ($record->legsOffsetEachOther()) {
            return '<strong>These two differences cancel each other out.</strong> That is almost always one sale '
                .'rung on the wrong tender. Explain it as a wrong-tender correction before approving, or the '
                .'cashier will be charged for money that is sitting on the terminal.';
        }

        if (abs($cash) < 0.01) {
            return 'Nothing is left unexplained. Nobody will be charged anything.';
        }

        return $cash < 0
            ? '<strong>₦'.number_format(abs($cash), 2).'</strong> will be recorded as owed by '
                .e($record->cashier->name).'.'
            : '<strong>₦'.number_format($cash, 2).'</strong> more than expected will be credited to '
                .e($record->cashier->name).'.';
    }

    private function gapSummary(PosSession $record): string
    {
        $record->loadMissing('rectifications');

        return sprintf(
            'Drawer is %s by ₦%s. Terminal is %s by ₦%s.',
            $record->resolvedCashVariance() < 0 ? 'short' : 'over',
            number_format(abs($record->resolvedCashVariance()), 2),
            $record->resolvedTerminalVariance() < 0 ? 'short' : 'over',
            number_format(abs($record->resolvedTerminalVariance()), 2),
        );
    }

    /**
     * The sales this day's correction could possibly be about.
     *
     * Scoped to the cashier, the branch and the trading day — the same three
     * facts the reconciliation itself is keyed on, so a correction can never be
     * aimed at somebody else's sale. The action re-checks this; a tampered
     * select must not be the only thing standing in the way.
     */
    private function saleOptions(PosSession $record, ?string $kind): array
    {
        [$from, $to] = BusinessDate::boundsFor($record->business_date->toDateString());

        return PosSale::query()
            ->where('vendor_id', $record->vendor_id)
            ->where('store_id', $record->store_id)
            ->where('cashier_id', $record->cashier_id)
            ->where('status', '!=', 'voided')
            ->whereBetween('completed_at', [$from, $to])
            // A credit sale is the only kind that can have been "actually paid".
            ->when(
                $kind === CashUpRectification::KIND_DEBT_PAID,
                fn ($q) => $q->whereIn('payment_method', ['debt', 'split']),
            )
            ->orderByDesc('completed_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (PosSale $sale) => [
                $sale->id => sprintf(
                    '%s — ₦%s (%s)',
                    $sale->reference,
                    number_format((float) $sale->total, 2),
                    str_replace('_', ' ', (string) $sale->payment_method),
                ),
            ])
            ->all();
    }

    private static function varianceColour(float $variance): string
    {
        if (abs($variance) < 0.01) {
            return 'gray';
        }

        return $variance < 0 ? 'danger' : 'warning';
    }
}
