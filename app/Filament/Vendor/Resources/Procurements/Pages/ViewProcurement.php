<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\FinancialAccount;
use App\Models\Procurement;
use App\Models\ProcurementLogisticsLeg;
use App\Services\ActiveStore;
use App\Services\FinancialLedger;
use App\Services\Procurement\ProcurementReview;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Override;

class ViewProcurement extends ViewRecord
{
    protected static string $resource = ProcurementResource::class;

    public function infolist(Schema $schema): Schema
    {
        $record = $this->record;

        return $schema->schema([
            Section::make('Procurement')->schema([
                Placeholder::make('reference')->label('Reference')
                    ->content(new HtmlString('<span class="font-bold font-mono">' . e($record->reference) . '</span>')),
                Placeholder::make('supplier')->label('Supplier')
                    ->content($record->supplier->name ?? '—'),
                Placeholder::make('destination')->label('Deliver To')
                    ->content($record->store->name ?? 'Default store'),
                Placeholder::make('status')->label('Status')
                    ->content(fn () => new HtmlString($this->statusBadgeHtml($record))),
                Placeholder::make('payment_status')->label('Payment')
                    ->content(new HtmlString($this->badge(
                        match ($record->payment_status) {
                            'full'         => 'Fully Paid',
                            'part_payment' => 'Part-Payment',
                            'credit'       => 'Credit (₦0 paid)',
                            default        => $record->payment_status,
                        },
                        match ($record->payment_status) {
                            'full'         => 'success',
                            'part_payment' => 'warning',
                            'credit'       => 'danger',
                            default        => 'gray',
                        }
                    ))),
                Placeholder::make('total_cost')->label('Total Cost')
                    ->content('₦' . number_format($record->total_cost, 2)),
                Placeholder::make('amount_paid')->label('Amount Paid')
                    ->content('₦' . number_format($record->amount_paid, 2)),
                Placeholder::make('creator')->label('Logged By')
                    ->content($record->creator->name ?? '—'),
                Placeholder::make('created_at')->label('Submitted')
                    ->content($record->created_at->format('d M Y, H:i')),
                Placeholder::make('void_reason')->label('Void Reason')
                    ->content($record->void_reason ?? '—')
                    ->visible($record->isVoided()),
                Placeholder::make('notes')->label('Notes')
                    ->content($record->notes ?? '—'),
            ])->columns(3),

            Section::make('Waybill Image')->schema([
                Placeholder::make('waybill')->label('')
                    ->content(new HtmlString(
                        '<img src="' . asset('storage/' . $record->waybill_image) . '" class="max-h-72 rounded-xl object-contain border" />'
                    )),
            ])->visible((bool) $record->waybill_image),

            // No Section wrapper: the cards carry their own headings and
            // borders, and nesting them inside another bordered panel puts a
            // second frame around every line on a 360px screen.
            View::make('filament.vendor.procurement.review-cards')
                ->viewData([
                    'record'      => $record,
                    'items'       => $record->items()->with(['product', 'corrections.correctedBy'])->get(),
                    'statusBadge' => $this->statusBadgeHtml($record),
                    'canApprove'  => auth()->user()->can('approve', $record)
                        && ProcurementResource::canApprove($record),
                    'canCorrect'  => auth()->user()->can('correct', $record)
                        && ProcurementResource::canApprove($record),
                ]),

            Section::make('Transport Cost')
                ->description('What it cost to move this stock to your store — kept separate from what you paid the supplier for the goods, so the two are never mixed up in your reports.')
                ->schema([
                    Placeholder::make('legs_table')->label('')
                        ->content(new HtmlString($this->buildLegsTable($record))),
                ])
                ->visible($record->legs()->exists()),
        ]);
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();
        return
            [
                Action::make('approve')
                    ->label(fn () => $this->record->hasCorrections() ? 'Agree & Update Stock' : 'Approve & Update Stock')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->size('lg')
                    ->requiresConfirmation()
                    ->modalHeading(fn () => $this->record->hasCorrections() ? 'Agree These Figures' : 'Approve Procurement')
                    ->modalDescription(fn () => sprintf(
                        'Receiving %s will put %d units into %s at a total of %s, and update cost/selling prices.',
                        $this->record->reference,
                        $this->record->verifiedQuantity(),
                        $this->record->store->name ?? 'the default store',
                        '₦' . number_format($this->record->verifiedTotal(), 2),
                    ))
                    // Visibility only mirrors the policy now. The policy is the
                    // gate — ProcurementReview refuses regardless of what this
                    // page chose to render, so reaching the action directly is
                    // refused exactly as if the button had never been there.
                    ->visible(fn () => auth()->user()->can('approve', $this->record)
                        && ProcurementResource::canApprove($this->record))
                    ->action(function (ProcurementReview $review) {
                        try {
                            // Received into the store the approver is working
                            // in, not blindly into the vendor's default one.
                            $review->approve($this->record, auth()->user(), ActiveStore::currentId());
                            Notification::make()->title('Procurement Approved, Inventory Updated.')->success()->send();
                            $this->refreshFormData(['status', 'approved_by', 'approved_at', 'awaiting_user_id', 'total_cost']);
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error: ' . $e->getMessage())->danger()->send();
                        }
                    }),

                // Saying a line is wrong. One action for the whole batch rather
                // than a button per card: the approver walks the delivery once
                // with the waybill in hand, and correcting three lines should
                // be one trip back to the storekeeper, not three.
                Action::make('correct')
                    ->label(fn () => $this->record->isChangesRequested() ? 'Still Not Right' : 'Correct Figures')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->size('lg')
                    ->modalHeading('What did you actually receive?')
                    ->modalDescription('Enter what you counted. The recorded figures are kept beside yours — nothing is overwritten — and the delivery goes back to the other party to agree.')
                    ->modalSubmitActionLabel('Send Back')
                    ->schema(fn () => $this->correctionFields())
                    ->visible(fn () => auth()->user()->can('correct', $this->record)
                        && ProcurementResource::canApprove($this->record))
                    ->action(function (array $data, ProcurementReview $review) {
                        try {
                            $review->correct(
                                $this->record,
                                auth()->user(),
                                $this->linesFromForm($data),
                                $data['correction_note'] ?? null,
                            );
                            Notification::make()
                                ->title('Sent back for re-check.')
                                ->body('The other party has been asked to confirm your figures.')
                                ->success()->send();
                            $this->refreshFormData(['status', 'awaiting_user_id']);
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error: ' . $e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('recordLogisticsPayment')
                    ->label('Pay Transport Cost')
                    ->icon('heroicon-o-banknotes')
                    ->color('gray')
                    ->size('lg')
                    ->requiresConfirmation()
                    ->modalHeading('Pay Transport Cost')
                    ->modalDescription('Deducts every unpaid stage above from the account you choose, all at once. Safe to run more than once — anything already paid is skipped, never charged twice.')
                    ->schema([
                        Select::make('financial_account_id')
                            ->label('Paid From')
                            ->options(fn () => FinancialAccount::where('vendor_id', $vendor->id)->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->visible(fn () => ! $this->record->isVoided()
                        && $this->record->legs()->whereNull('posted_at')->exists()
                        && $user->hasVendorPermission($vendor->id, 'manage_procurement'))
                    ->action(function (array $data): void {
                        $account = FinancialAccount::findOrFail($data['financial_account_id']);
                        $posted  = 0;

                        foreach ($this->record->legs()->whereNull('posted_at')->get() as $leg) {
                            FinancialLedger::postEntry(
                                account: $account,
                                direction: 'out',
                                amount: (float) $leg->amount,
                                source: $leg,
                                description: "Transport cost — {$leg->route_label} ({$this->record->reference})",
                                createdBy: auth()->id(),
                            );

                            $leg->update(['financial_account_id' => $account->id, 'posted_at' => now()]);
                            $posted++;
                        }

                        Notification::make()->title("Paid {$posted} transport stage(s).")->success()->send();
                        $this->refreshFormData([]);
                    }),

                Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->size('lg')
                    ->requiresConfirmation()
                    ->modalHeading('Void this Procurement')
                    ->form([
                        Textarea::make('void_reason')->label('Void Reason')
                            ->required()->minLength(10)
                            ->placeholder('Explain why this record is being voided...')
                    ])
                    ->visible(fn () => $this->record->isOpen() && $user->hasVendorPermission($vendor->id, 'manage_inventory'))
                    ->action(function (array $data) {
                        $this->record->update(['status' => 'voided', 'void_reason' => $data['void_reason']]);
                        Notification::make()->title('Procurement Voided')
                            ->warning()->send();
                        $this->refreshFormData(['status', 'void_reason']);
                    }),
            ];
    }

    private function buildLegsTable(Procurement $record): string
    {
        $rows = '';
        foreach ($record->legs as $leg) {
            $status = $leg->isPosted()
                ? $this->badge('Paid — ' . ($leg->financialAccount->name ?? '—'), 'success')
                : $this->badge('Unpaid', 'warning');

            $rows .= "<tr class='border-b border-gray-100 dark:border-gray-700'>
                <td class='px-4 py-3 text-sm font-medium'>" . e($leg->route_label) . "</td>
                <td class='px-4 py-3 text-sm'>₦" . number_format($leg->amount, 2) . "</td>
                <td class='px-4 py-3 text-sm'>{$status}</td>
            </tr>";
        }

        $total = $record->logisticsTotal();

        return "<div class='overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700'>
            <table class='w-full text-left'>
                <thead>
                    <tr class='bg-gray-50 dark:bg-gray-800 text-xs font-semibold text-gray-500 uppercase tracking-wider'>
                        <th class='px-4 py-3'>Stage</th>
                        <th class='px-4 py-3'>Cost</th>
                        <th class='px-4 py-3'>Status</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
                <tfoot>
                    <tr>
                        <td class='px-4 py-3 text-sm font-bold'>Total</td>
                        <td class='px-4 py-3 text-sm font-bold'>₦" . number_format($total, 2) . "</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>";
    }

    /**
     * Correct one line, from the card showing that line.
     *
     * Mounted by name from the Blade view with the item id as an argument, so
     * the form only ever asks about the carton in front of the person. The
     * batch-wide version of this lives in getHeaderActions() for anyone
     * checking a delivery off against a waybill instead of off a shelf.
     */
    public function correctLineAction(): Action
    {
        return Action::make('correctLine')
            ->modalHeading(fn (array $arguments) => 'Correct: '
                . ($this->lineFor($arguments)?->product->name ?? 'Item'))
            ->modalDescription('Enter what you actually counted. The recorded figures are kept beside yours — nothing is overwritten — and the delivery goes back to the other party to agree.')
            ->modalSubmitActionLabel('Send Back')
            ->schema(fn (array $arguments) => $this->lineFields($this->lineFor($arguments)))
            ->action(function (array $arguments, array $data, ProcurementReview $review) {
                $item = $this->lineFor($arguments);

                if (! $item) {
                    Notification::make()->title('That line is no longer part of this delivery.')->danger()->send();

                    return;
                }

                try {
                    $review->correct(
                        $this->record,
                        auth()->user(),
                        [$item->id => [
                            'quantity'  => $data['quantity'] ?? null,
                            'unit_cost' => $data['unit_cost'] ?? null,
                        ]],
                        $data['correction_note'] ?? null,
                    );

                    Notification::make()
                        ->title('Sent back for re-check.')
                        ->body('The other party has been asked to confirm your figures.')
                        ->success()->send();

                    $this->refreshFormData(['status', 'awaiting_user_id']);
                } catch (\Throwable $e) {
                    Notification::make()->title('Error: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    /** @param  array<string, mixed>  $arguments */
    private function lineFor(array $arguments): ?\App\Models\ProcurementItem
    {
        $id = $arguments['item'] ?? null;

        return $id === null
            ? null
            : $this->record->items()->with('product')->find((int) $id);
    }

    /**
     * The two correctable figures, prefilled with what the line currently
     * says. Nothing else is editable — the supplier and the product are facts
     * of the delivery, not opinions about it.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function lineFields(?\App\Models\ProcurementItem $item): array
    {
        if (! $item) {
            return [];
        }

        return [
            TextInput::make('quantity')
                ->label('Quantity received')
                ->helperText('Recorded: ' . number_format($item->quantity))
                ->numeric()->minValue(0)->required()
                ->default($item->verifiedQuantity()),
            TextInput::make('unit_cost')
                ->label('Unit cost')
                ->helperText('Recorded: ₦' . number_format((float) $item->unit_cost, 2))
                ->numeric()->minValue(0)->required()
                ->prefix('₦')
                ->default($item->verifiedUnitCost()),
            Textarea::make('correction_note')
                ->label('Note (optional)')
                ->placeholder('e.g. two cartons short on the pallet')
                ->rows(2),
        ];
    }

    private function statusBadgeHtml(Procurement $record): string
    {
        return $this->badge(
            match ($record->status) {
                'pending'           => 'Awaiting Check',
                'changes_requested' => 'Sent Back',
                default             => ucfirst($record->status),
            },
            match ($record->status) {
                'pending'           => 'warning',
                'changes_requested' => 'info',
                'approved'          => 'success',
                'voided'            => 'danger',
                default             => 'gray',
            },
        );
    }

    /**
     * One row per line, prefilled with what the line is currently understood
     * to be, so agreeing with most of a delivery means changing only the line
     * that is wrong.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function correctionFields(): array
    {
        $fields = [];

        foreach ($this->record->items()->with(['product', 'corrections'])->get() as $item) {
            $fields[] = Section::make($item->product->name ?? 'Item')
                ->description(sprintf(
                    'Recorded: %d @ %s',
                    $item->quantity,
                    '₦' . number_format((float) $item->unit_cost, 2),
                ))
                ->schema([
                    TextInput::make("lines.{$item->id}.quantity")
                        ->label('Quantity received')
                        ->numeric()->minValue(0)->required()
                        ->default($item->verifiedQuantity()),
                    TextInput::make("lines.{$item->id}.unit_cost")
                        ->label('Unit cost')
                        ->numeric()->minValue(0)->required()
                        ->prefix('₦')
                        ->default($item->verifiedUnitCost()),
                ])->columns(2);
        }

        $fields[] = Textarea::make('correction_note')
            ->label('Note (optional)')
            ->placeholder('e.g. two cartons short on the pallet')
            ->rows(2);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{quantity: int, unit_cost: float}>
     */
    private function linesFromForm(array $data): array
    {
        $lines = [];

        foreach ($data['lines'] ?? [] as $itemId => $values) {
            $lines[(int) $itemId] = [
                'quantity'  => $values['quantity'] ?? null,
                'unit_cost' => $values['unit_cost'] ?? null,
            ];
        }

        return $lines;
    }

    private function badge(string $label, string $color): string
    {
        $classes = match ($color) {
            'success' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'warning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            'danger'  => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            'info'     => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            default   => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };
        return "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {$classes}'>{$label}</span>";
    }
}
