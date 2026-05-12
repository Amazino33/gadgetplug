<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\InventoryLedger;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class StockMovementChart extends ChartWidget
{
    protected ?string $heading = 'Stock Movement — Last 14 Days';
    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $vendorId = Filament::getTenant()?->id;
        $start    = Carbon::today()->subDays(13)->startOfDay();

        // Single query — filter by vendor and date range
        $entries = InventoryLedger::where('vendor_id', $vendorId)
            ->where('created_at', '>=', $start)
            ->get(['transaction_type', 'quantity_change', 'created_at']);

        $labels  = [];
        $sold    = [];
        $restock = [];

        foreach (range(13, 0) as $offset) {
            $day      = Carbon::today()->subDays($offset);
            $labels[] = $day->format('d M');

            $dayEntries = $entries->filter(
                fn ($e) => Carbon::parse($e->created_at)->isSameDay($day)
            );

            $sold[] = abs((int) $dayEntries
                ->whereIn('transaction_type', ['online_sale', 'pos_sale'])
                ->sum('quantity_change'));

            $restock[] = max(0, (int) $dayEntries
                ->where('transaction_type', 'restock')
                ->sum('quantity_change'));
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Units Sold',
                    'data'            => $sold,
                    'backgroundColor' => '#f97316',
                    'borderRadius'    => 4,
                ],
                [
                    'label'           => 'Units Restocked',
                    'data'            => $restock,
                    'backgroundColor' => '#068B03',
                    'borderRadius'    => 4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks'       => ['precision' => 0],
                    'grid'        => ['color' => 'rgba(0,0,0,0.05)'],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'top'],
            ],
        ];
    }
}
