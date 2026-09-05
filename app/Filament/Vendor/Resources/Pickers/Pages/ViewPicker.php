<?php

namespace App\Filament\Vendor\Resources\Pickers\Pages;

use App\Filament\Vendor\Resources\Pickers\PickerResource;
use App\Filament\Vendor\Resources\Pickers\RelationManagers\HoldingsRelationManager;
use App\Models\Picker;
use App\Models\PickingLedgerEntry;
use App\Services\Pickings\PickingLedger;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ViewPicker extends ViewRecord
{
    protected static string $resource = PickerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            HoldingsRelationManager::class,
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        /** @var Picker $picker */
        $picker = $this->record;

        return $schema->components([
            Section::make('Picker')
                ->columns(['sm' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('phone')->label('Phone')->placeholder('—'),
                    TextEntry::make('shop')->label('Shop')->placeholder('—'),

                    TextEntry::make('units_held')
                        ->label('Units held')
                        ->state(fn () => number_format($this->heldUnits($picker))),

                    TextEntry::make('value_out')
                        ->label('Worth today')
                        // Valued at the price they will actually be asked to
                        // pay, so this figure moves when prices move.
                        ->state(fn () => '₦' . number_format($this->valueOut($picker), 2))
                        ->weight('bold'),
                ]),

            Section::make('Payment history')
                ->description('Every payment, return and write-off against this picker, newest first.')
                ->collapsible()
                ->schema([
                    TextEntry::make('history')
                        ->label('')
                        ->state(fn () => new HtmlString($this->historyTable($picker))),
                ]),
        ]);
    }

    /** @var array{units: int, value: float}|null */
    private ?array $outstanding = null;

    /**
     * A picker holding nothing is absent from the list entirely, which is
     * correct there and would be a fatal here — hence the empty row rather than
     * an index into a null.
     *
     * @return array{units: int, value: float}
     */
    private function outstanding(Picker $picker): array
    {
        return $this->outstanding ??= PickingLedger::outstandingByPicker($picker->vendor_id)
            ->firstWhere('picker_id', $picker->id)
            ?? ['units' => 0, 'value' => 0.0];
    }

    private function heldUnits(Picker $picker): int
    {
        return (int) $this->outstanding($picker)['units'];
    }

    private function valueOut(Picker $picker): float
    {
        return (float) $this->outstanding($picker)['value'];
    }

    /**
     * The ledger as it stands, rendered rather than tabled because it is a
     * read-only record — nothing here can be actioned, only looked at.
     */
    private function historyTable(Picker $picker): string
    {
        $entries = PickingLedgerEntry::query()
            ->join('picking_items', 'picking_items.id', '=', 'picking_ledger_entries.picking_item_id')
            ->join('pickings', 'pickings.id', '=', 'picking_items.picking_id')
            ->join('products', 'products.id', '=', 'picking_items.product_id')
            ->leftJoin('users', 'users.id', '=', 'picking_ledger_entries.user_id')
            ->where('pickings.picker_id', $picker->id)
            ->orderByDesc('picking_ledger_entries.created_at')
            ->limit(100)
            ->get([
                'picking_ledger_entries.*',
                'products.name as product_name',
                'pickings.reference as picking_reference',
                'users.name as staff_name',
            ]);

        if ($entries->isEmpty()) {
            return '<p class="text-sm text-gray-500 dark:text-gray-400">Nothing recorded yet.</p>';
        }

        $rows = $entries->map(function ($entry) {
            $label = match ($entry->direction) {
                PickingLedgerEntry::DIRECTION_PAYMENT  => '<span class="text-green-600 dark:text-green-400">Paid</span>',
                PickingLedgerEntry::DIRECTION_RETURN   => '<span class="text-gray-600 dark:text-gray-300">Returned</span>',
                PickingLedgerEntry::DIRECTION_WRITEOFF => '<span class="text-red-600 dark:text-red-400">Written off</span>',
                default                                => e($entry->direction),
            };

            $amount = (float) $entry->amount > 0
                ? '₦' . number_format((float) $entry->amount, 2)
                : '—';

            return '<tr class="border-b border-gray-100 dark:border-white/10">'
                . '<td class="py-2 pr-4 text-gray-500 dark:text-gray-400">' . e($entry->created_at?->format('d M Y')) . '</td>'
                . '<td class="py-2 pr-4">' . $label . '</td>'
                . '<td class="py-2 pr-4 text-gray-950 dark:text-white">' . e($entry->product_name) . '</td>'
                . '<td class="py-2 pr-4 text-right">' . (int) $entry->quantity . '</td>'
                . '<td class="py-2 pr-4 text-right">' . $amount . '</td>'
                . '<td class="py-2 text-gray-500 dark:text-gray-400">' . e($entry->staff_name ?? '—') . '</td>'
                . '</tr>';
        })->implode('');

        return '<div class="overflow-x-auto"><table class="w-full text-sm">'
            . '<thead><tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">'
            . '<th class="pb-2 pr-4 text-left font-medium">When</th>'
            . '<th class="pb-2 pr-4 text-left font-medium">What</th>'
            . '<th class="pb-2 pr-4 text-left font-medium">Product</th>'
            . '<th class="pb-2 pr-4 text-right font-medium">Units</th>'
            . '<th class="pb-2 pr-4 text-right font-medium">Amount</th>'
            . '<th class="pb-2 text-left font-medium">By</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
