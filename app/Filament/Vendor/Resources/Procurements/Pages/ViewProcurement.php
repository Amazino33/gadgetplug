<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
        ]);
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
