<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Actions\Procurement\ApproveProcurementAction;
use App\Actions\Procurement\CorrectProcurementLogisticsAction;
use App\Actions\Procurement\VoidProcurementAction;
use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Override;
use Throwable;

class ViewProcurement extends ViewRecord
{
    protected static string $resource = ProcurementResource::class;

    public function infolist(Schema $schema): Schema
    {
        $record = $this->record;

        return $schema->schema([
            Section::make('Procurement')->schema([
                Placeholder::make('reference')->label('Reference')
                    ->content(new HtmlString('<span class="font-bold font-mono">'.e($record->reference).'</span>')),
                Placeholder::make('supplier')->label('Supplier')
                    ->content($record->supplier->name ?? '—'),
                Placeholder::make('status')->label('Status')
                    ->content(new HtmlString($this->badge($record->status, match ($record->status) {
                        'pending', 'draft' => 'warning',
                        'awaiting_logistics' => 'info',
                        'approved', 'reconciled' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    }))),
                Placeholder::make('payment_status')->label('Payment')
                    ->content(new HtmlString($this->badge(
                        match ($record->payment_status) {
                            'full' => 'Fully Paid',
                            'part_payment' => 'Part-Payment',
                            'credit' => 'Credit (₦0 paid)',
                            default => $record->payment_status,
                        },
                        match ($record->payment_status) {
                            'full' => 'success',
                            'part_payment' => 'warning',
                            'credit' => 'danger',
                            default => 'gray',
                        }
                    ))),
                Placeholder::make('payment_method')->label('Payment Method')
                    ->content(fn () => $record->payment_method ? ucfirst(str_replace('_', ' ', $record->payment_method)) : '—'),
                Placeholder::make('total_cost')->label('Total Cost')
                    ->content('₦'.number_format($record->total_cost, 2)),
                Placeholder::make('amount_paid')->label('Amount Paid')
                    ->content('₦'.number_format($record->amount_paid, 2)),
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
                        '<img src="'.asset('storage/'.$record->waybill_image).'" class="max-h-72 rounded-xl object-contain border" />'
                    )),
            ])->visible((bool) $record->waybill_image),

            Section::make('Items')->schema([
                Placeholder::make('items_table')->label('')
                    ->content(new HtmlString($this->buildItemsTable($record))),
            ]),
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
                    ->modalDescription(fn () => "Approving {$this->record->reference} will restock {$this->record->items()->count()} products and update their cost/selling price."
                    )
                    ->visible(fn () => $this->record->isPending() && $user->hasVendorPermission($vendor->id, 'approve_procurement') && ($this->record->created_by !== auth()->id() || ! $vendor->hasOtherApprovers($this->record->created_by)))
                    ->action(function (ApproveProcurementAction $approveAction) {
                        try {
                            $approveAction->execute($this->record);
                            Notification::make()->title('Procurement Approved, Inventory Updated.')->success()->send();
                            $this->refreshFormData(['status', 'approved_by', 'approved_at']);
                        } catch (Throwable $e) {
                            Notification::make()->title('Error: '.$e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->size('lg')
                    ->requiresConfirmation()
                    ->modalHeading('Void this Procurement')
                    ->modalDescription(fn () => $this->record->isAwaitingLogistics()
                        ? "This will reverse the {$this->record->items()->count()} product(s) restocked when this procurement was submitted. Provisional cost/price already applied to those products will NOT be reverted."
                        : 'This procurement has not affected stock or pricing yet, so voiding it is a simple status change.')
                    ->form([
                        Textarea::make('void_reason')->label('Void Reason')
                            ->required()->minLength(10)
                            ->placeholder('Explain why this record is being voided...'),
                    ])
                    ->visible(fn () => in_array($this->record->status, ['draft', 'pending', 'awaiting_logistics'], true)
                        && $user->hasVendorPermission($vendor->id, 'manage_inventory'))
                    ->action(function (array $data, VoidProcurementAction $action) {
                        try {
                            $action->execute($this->record, $data['void_reason']);
                            Notification::make()->title('Procurement Voided')->warning()->send();
                            $this->refreshFormData(['status', 'void_reason']);
                        } catch (Throwable $e) {
                            Notification::make()->title('Error: '.$e->getMessage())->danger()->send();
                        }
                    }),

                // Reaches the repeater-based line/logistics editing and the
                // Submit/Reconcile actions — visibility follows
                // ProcurementResource::canEdit() (draft or awaiting_logistics
                // only, gated by submit_procurement/record_procurement_logistics).
                EditAction::make(),

                // Fixes a wrong trip logistics_cost AFTER reconciling, without
                // ever re-opening the procurement to draft/awaiting_logistics —
                // reconciliation stays a one-way status transition; this just
                // recalculates in place via CorrectProcurementLogisticsAction.
                Action::make('correct_logistics')
                    ->label('Correct Logistics Cost')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->schema([
                        TextInput::make('logistics_cost')
                            ->label('Corrected Trip Logistics Cost')
                            ->numeric()
                            ->prefix('₦')
                            ->minValue(0)
                            ->required()
                            ->default(fn () => $this->record->logistics_cost),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Correct logistics cost')
                    ->modalDescription('This recalculates landed cost and suggested price for every line using the corrected figure, and updates any non-overridden product prices. The procurement stays reconciled — this does not reopen it.')
                    ->visible(fn () => $this->record->isReconciled() && $user->hasVendorPermission($vendor->id, 'record_procurement_logistics'))
                    ->action(function (array $data, CorrectProcurementLogisticsAction $action) {
                        try {
                            $action->execute($this->record, (float) $data['logistics_cost']);
                            Notification::make()->title('Logistics cost corrected — pricing recalculated.')->success()->send();
                            $this->refreshFormData(['status']);
                        } catch (Throwable $e) {
                            Notification::make()->title('Error: '.$e->getMessage())->danger()->send();
                        }
                    }),
            ];
    }

    private function buildItemsTable(Procurement $record): string
    {
        $isProvisional = $record->isAwaitingLogistics();

        $rows = '';
        foreach ($record->items()->with('product')->get() as $item) {
            $landed = $item->landed_unit_cost !== null ? '₦'.number_format($item->landed_unit_cost, 2) : '—';
            // suggested_price is this line's real selling-price replacement
            // under auto-pricing — the legacy selling_price column is only
            // ever populated by the old wizard flow now, hence the null guard.
            $suggested = $item->suggested_price !== null ? '₦'.number_format($item->suggested_price, 2) : '—';
            $sellingPrice = $item->selling_price !== null ? '₦'.number_format($item->selling_price, 2) : '—';

            $rows .= "<tr class='border-b border-gray-100 dark:border-gray-700'>
                <td class='px-4 py-3 text-sm font-medium'>".e($item->product->name ?? '—')."</td>
                <td class='px-4 py-3 text-xs text-gray-500'>".e($item->barcode ?? '—')."</td>
                <td class='px-4 py-3 text-sm text-center'>{$item->quantity}</td>
                <td class='px-4 py-3 text-sm'>₦".number_format($item->unit_cost, 2)."</td>
                <td class='px-4 py-3 text-sm'>{$landed}</td>
                <td class='px-4 py-3 text-sm'>{$suggested}</td>
                <td class='px-4 py-3 text-sm'>{$sellingPrice}</td>
                <td class='px-4 py-3 text-sm font-semibold'>₦".number_format($item->lineTotal(), 2).'</td>
            </tr>';
        }

        $landedLabel = $isProvisional ? 'Landed Cost (provisional)' : 'Landed Cost';
        $suggestedLabel = $isProvisional ? 'Suggested (provisional)' : 'Suggested';

        return "<div class='overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700'>
            <table class='w-full text-left'>
                <thead>
                    <tr class='bg-gray-50 dark:bg-gray-800 text-xs font-semibold text-gray-500 uppercase tracking-wider'>
                        <th class='px-4 py-3'>Product</th>
                        <th class='px-4 py-3'>Barcode</th>
                        <th class='px-4 py-3 text-center'>Qty</th>
                        <th class='px-4 py-3'>Purchase Price</th>
                        <th class='px-4 py-3'>{$landedLabel}</th>
                        <th class='px-4 py-3'>{$suggestedLabel}</th>
                        <th class='px-4 py-3'>Selling Price</th>
                        <th class='px-4 py-3'>Line Total</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>";
    }

    private function badge(string $label, string $color): string
    {
        $classes = match ($color) {
            'success' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'warning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            'danger' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            'info' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };

        return "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {$classes}'>{$label}</span>";
    }
}
