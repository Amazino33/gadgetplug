<?php

namespace App\Filament\Resources\Procurements\Pages;

use App\Actions\Procurement\ApproveProcurementAction;
use App\Filament\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ViewProcurement extends ViewRecord
{
    protected static string $resource = ProcurementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve & Update Stock')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->size('lg')
                ->requiresConfirmation()
                ->modalHeading('Approve Procurement')
                ->modalDescription(fn () => "Approving {$this->record->reference} will restock {$this->record->items()->count()} product(s) and update their cost/selling prices.")
                ->visible(fn () => $this->record->isPending())
                ->action(function (ApproveProcurementAction $action) {
                    try {
                        $action->execute($this->record);
                        Notification::make()->title('Procurement approved. Inventory updated.')->success()->send();
                        $this->refreshFormData(['status', 'approved_by', 'approved_at']);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error: ' . $e->getMessage())->danger()->send();
                    }
                }),

            Action::make('void')
                ->label('Void')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Void this Procurement')
                ->form([
                    Textarea::make('void_reason')->label('Void Reason')->required()->minLength(10)
                        ->placeholder('Explain why this record is being voided…'),
                ])
                ->visible(fn () => $this->record->isPending())
                ->action(function (array $data) {
                    $this->record->update(['status' => 'voided', 'void_reason' => $data['void_reason']]);
                    Notification::make()->title('Procurement voided.')->warning()->send();
                    $this->refreshFormData(['status', 'void_reason']);
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $record = $this->record;

        return $schema->schema([
            Section::make('Overview')->schema([
                Grid::make(3)->schema([
                    Placeholder::make('reference')->label('Reference')
                        ->content(new HtmlString('<span class="font-bold font-mono">' . e($record->reference) . '</span>')),
                    Placeholder::make('vendor')->label('Store')
                        ->content($record->vendor->name ?? '—'),
                    Placeholder::make('supplier')->label('Supplier')
                        ->content($record->supplier->name ?? '—'),
                    Placeholder::make('status')->label('Status')
                        ->content(new HtmlString($this->badgeHtml($record->status, match ($record->status) {
                            'pending'  => 'warning',
                            'approved' => 'success',
                            'voided'   => 'danger',
                            default    => 'gray',
                        }))),
                    Placeholder::make('payment_status')->label('Payment')
                        ->content(new HtmlString($this->badgeHtml(
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
                ]),
                Grid::make(1)->schema([
                    Placeholder::make('void_reason')->label('Void Reason')
                        ->content($record->void_reason ?? '—')
                        ->visible($record->isVoided()),
                    Placeholder::make('notes')->label('Notes')
                        ->content($record->notes ?? '—'),
                ]),
            ]),

            Section::make('Waybill Image')->schema([
                Placeholder::make('waybill')->label('')
                    ->content(new HtmlString(
                        '<img src="' . asset('storage/' . $record->waybill_image) . '" class="max-h-80 rounded-xl object-contain border" />'
                    )),
            ])->visible((bool) $record->waybill_image),

            Section::make('Items')->schema([
                Placeholder::make('items_table')->label('')
                    ->content(new HtmlString($this->buildItemsTable($record))),
            ]),
        ]);
    }

    private function buildItemsTable(Procurement $record): string
    {
        $rows = '';
        foreach ($record->items()->with('product')->get() as $item) {
            $pct      = $item->costVariancePct();
            $variance = $pct === null
                ? '<span class="text-gray-400 text-xs">—</span>'
                : '<span class="text-xs font-semibold px-2 py-0.5 rounded-full ' .
                  (abs($pct) > 10 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-700') . '">' .
                  ($pct > 0 ? '+' : '') . number_format($pct, 1) . '%</span>';

            $rows .= "<tr class='border-b border-gray-100 dark:border-gray-700'>
                <td class='px-4 py-3 text-sm font-medium'>" . e($item->product->name ?? '—') . "</td>
                <td class='px-4 py-3 text-xs text-gray-500'>" . e($item->barcode ?? '—') . "</td>
                <td class='px-4 py-3 text-sm text-center'>{$item->quantity}</td>
                <td class='px-4 py-3 text-sm'>₦" . number_format($item->unit_cost, 2) . "</td>
                <td class='px-4 py-3 text-sm'>₦" . number_format($item->selling_price, 2) . "</td>
                <td class='px-4 py-3'>{$variance}</td>
                <td class='px-4 py-3 text-sm font-semibold'>₦" . number_format($item->lineTotal(), 2) . "</td>
            </tr>";
        }

        return "<div class='overflow-x-auto'>
            <table class='w-full text-left'>
                <thead>
                    <tr class='bg-gray-50 dark:bg-gray-800 text-xs font-semibold text-gray-500 uppercase tracking-wider'>
                        <th class='px-4 py-3'>Product</th>
                        <th class='px-4 py-3'>Barcode</th>
                        <th class='px-4 py-3 text-center'>Qty</th>
                        <th class='px-4 py-3'>Unit Cost</th>
                        <th class='px-4 py-3'>Sell Price</th>
                        <th class='px-4 py-3'>Cost Variance</th>
                        <th class='px-4 py-3'>Line Total</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>";
    }

    private function badgeHtml(string $label, string $color): string
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
