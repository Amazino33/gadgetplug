<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\StockTake;
use App\Services\StockTake\StockTakeScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * How far the stock take has got. A product counted in a handed-in task is
 * counted, whatever later tasks hold (decision 5).
 */
class StockTakeProgress extends StatsOverviewWidget
{
    // Two to a row on a phone, so the counts are reached without a long scroll.
    protected int|array|null $columns = ['default' => 2, 'lg' => 4];

    protected static bool $isDiscovered = false;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    #[On('stock-take-updated')]
    public function refreshStats(): void {}

    protected function getStats(): array
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;
        $p = StockTakeScope::progress($stockTake);

        $tenths = (int) round($p['percent'] / 10);
        $bar = $p['in_scope'] > 0
            ? str_repeat('█', $tenths) . str_repeat('░', 10 - $tenths) . " {$p['percent']}%"
            : 'Nothing mapped yet';

        $recheck = \App\Models\StockTakeResult::query()
            ->where('stock_take_id', $stockTake->id)
            ->whereNotNull('recheck_needed_at')
            ->count();

        return [
            ...($recheck > 0 ? [
                Stat::make('Need re-check', $recheck)
                    ->description('Late till sales the automatic correction did not post. Use Re-check in Results.')
                    ->color('danger'),
            ] : []),
            Stat::make('In scope', $p['in_scope'])
                ->description($stockTake->carried_product_ids !== null ? 'Carried over' : 'Products on active aisles'),
            Stat::make('Counted', $p['counted'])
                ->description($bar)
                ->color('success'),
            Stat::make('Approved', $p['approved'])
                ->description('Stock corrected'),
            Stat::make('Remaining', $p['remaining'])
                ->description($p['counted_off_map'] > 0 ? "Also counted, not on any aisle: {$p['counted_off_map']}" : 'Not counted yet')
                ->color($p['remaining'] > 0 ? 'warning' : 'success'),
        ];
    }
}
