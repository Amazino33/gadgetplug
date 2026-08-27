<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Actions\Procurement\ApproveProcurementAction;
use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\FinancialAccount;
use App\Models\Procurement;
use App\Models\ProcurementLogisticsLeg;
use App\Services\ActiveStore;
use App\Services\FinancialLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
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
                    ->content(new HtmlString($this->badge($record->status, match ($record->status) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'voided'   => 'danger',
                        default    => 'gray',
                    }))),
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

            Section::make('Items')->schema([
                Placeholder::make('items_table')->label('')
                    ->content(new HtmlString($this->buildItemsTable($record))),
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
                    ->label('Approve & Update Stock')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->size('lg')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Procurement')
                    ->modalDescription(fn () => sprintf(
                        'Approving %s will restock %d products into %s and update their cost/selling price.',
                        $this->record->reference,
                        $this->record->items()->count(),
                        $this->record->store->name ?? 'the default store',
                    ))
                    ->visible(fn () => $this->record->isPending() && $user->hasVendorPermission($vendor->id, 'approve_procurement') && ($this->record->created_by !== auth()->id() || !$vendor->hasOtherApprovers($this->record->created_by)))
                    ->action(function (ApproveProcurementAction $approveAction) {
                        try {
                            // Received into the store the approver is working
                            // in, not blindly into the vendor's default one.
                            $approveAction->execute($this->record, ActiveStore::currentId());
                            Notification::make()->title('Procurement Approved, Inventory Updated.')->success()->send();
                            $this->refreshFormData(['status', 'approved_by', 'approved_at']);
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
                    ->visible(fn () => $this->record->isPending() && $user->hasVendorPermission($vendor->id, 'manage_inventory'))
                    ->action(function (array $data) {
                        $this->record->update(['status' => 'voided', 'void_reason' => $data['void_reason']]);
                        Notification::make()->title('Procurement Voided')
                            ->warning()->send();
                        $this->refreshFormData(['status', 'void_reason']);
                    }),
            ];
    }

    private function buildItemsTable(Procurement $record): string
    {
        $rows = '';
        foreach ($record->items()->with('product')->get() as $item) {
            $rows .= "<tr class='border-b border-gray-100 dark:border-gray-700'>
                <td class='px-4 py-3 text-sm font-medium'>" . e($item->product->name ?? '—') . "</td>
                <td class='px-4 py-3 text-xs text-gray-500'>" . e($item->barcode ?? '—') . "</td>
                <td class='px-4 py-3 text-sm text-center'>{$item->quantity}</td>
                <td class='px-4 py-3 text-sm'>₦" . number_format($item->unit_cost, 2) . "</td>
                <td class='px-4 py-3 text-sm'>₦" . number_format($item->selling_price, 2) . "</td>
                <td class='px-4 py-3 text-sm font-semibold'>₦" . number_format($item->lineTotal(), 2) . "</td>
            </tr>";
        }

        return "<div class='overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700'>
            <table class='w-full text-left'>
                <thead>
                    <tr class='bg-gray-50 dark:bg-gray-800 text-xs font-semibold text-gray-500 uppercase tracking-wider'>
                        <th class='px-4 py-3'>Product</th>
                        <th class='px-4 py-3'>Barcode</th>
                        <th class='px-4 py-3 text-center'>Qty</th>
                        <th class='px-4 py-3'>Unit Cost</th>
                        <th class='px-4 py-3'>Selling Price</th>
                        <th class='px-4 py-3'>Line Total</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>";
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

    private function badge(string $label, string $color): string
    {
        $classes = match ($color) {
            'success' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'warning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            'danger'  => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            default   => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };
        return "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {$classes}'>{$label}</span>";
    }
}
