<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $record = $this->record;

        return $schema->schema([
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema([
                    Section::make('Images')
                        ->schema([
                            Placeholder::make('gallery')
                                ->label('')
                                ->content(fn (): HtmlString => new HtmlString(
                                    view('filament.vendor.products.gallery', ['record' => $record])->render()
                                )),
                        ]),

                    Section::make()
                        ->schema([
                            Placeholder::make('eyebrow')
                                ->label('')
                                ->content(new HtmlString(
                                    '<div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">'
                                        . e(trim(collect([$record->category?->name, $record->brand])->filter()->implode(' · ')) ?: '—')
                                        . '<span>&middot;</span>'
                                        . $this->statusBadge($record)
                                    . '</div>'
                                )),

                            Placeholder::make('name')
                                ->label('')
                                ->content(new HtmlString(
                                    '<h2 class="text-xl font-bold text-gray-950 dark:text-white">' . e($record->name) . '</h2>'
                                )),

                            Placeholder::make('sku')
                                ->label('SKU')
                                ->content($record->sku ?? '—'),

                            Placeholder::make('pricing')
                                ->label('')
                                ->content(fn (): HtmlString => new HtmlString($this->pricingBlock($record))),

                            Placeholder::make('stock')
                                ->label('')
                                ->content(fn (): HtmlString => new HtmlString($this->stockTiles($record))),
                        ]),
                ]),
        ]);
    }

    private function statusBadge(Product $record): string
    {
        $status = ($record->status === 'published' && $record->unpublish_at?->isPast())
            ? 'expired'
            : $record->status;

        $classes = match ($status) {
            'published' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'draft'     => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
            'archived'  => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            'expired'   => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            default     => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };

        return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $classes . '">'
            . e(ucfirst($status)) . '</span>';
    }

    private function money(?float $value): string
    {
        return $value === null ? '—' : '₦' . number_format($value, 2);
    }

    private function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1) . '%';
    }

    private function pricingBlock(Product $record): string
    {
        $rows = [
            ['Cost price', $this->money($record->cost_price !== null ? (float) $record->cost_price : null)],
            ['Selling price', $this->money((float) $record->price)],
            ['Profit / unit', $this->money($record->profit)],
            ['Margin', $this->percent($record->margin_percent)],
            ['Markup', $this->percent($record->markup_percent)],
        ];

        $html = '<div class="mt-4 rounded-xl border border-gray-200 dark:border-white/10 divide-y divide-gray-100 dark:divide-white/5">';
        foreach ($rows as [$label, $value]) {
            $html .= '<div class="flex items-center justify-between px-4 py-2.5">'
                . '<span class="text-sm text-gray-500 dark:text-gray-400">' . e($label) . '</span>'
                . '<span class="text-sm font-semibold text-gray-950 dark:text-white">' . e($value) . '</span>'
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function stockTiles(Product $record): string
    {
        $tiles = [
            ['On shelf', $record->stock_quantity, 'text-gray-950 dark:text-white'],
            ['Reserved', $record->reserved_stock, $record->reserved_stock > 0 ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-950 dark:text-white'],
            ['Available', $record->available_stock, match (true) {
                $record->available_stock === 0 => 'text-red-600 dark:text-red-400',
                $record->available_stock < 5   => 'text-yellow-600 dark:text-yellow-400',
                default                         => 'text-green-600 dark:text-green-400',
            }],
        ];

        $html = '<div class="mt-4 grid grid-cols-3 gap-3">';
        foreach ($tiles as [$label, $value, $colorClass]) {
            $html .= '<div class="rounded-xl border border-gray-200 dark:border-white/10 p-3 text-center">'
                . '<p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">' . e($label) . '</p>'
                . '<p class="mt-1 text-xl font-bold ' . $colorClass . '">' . e((string) $value) . '</p>'
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
