<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Filament\Vendor\Resources\StockTakes\RelationManagers\ResultsRelationManager;
use App\Models\StockTake;
use App\Models\StockTakeResult;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Shortage, surplus and net, in ₦ at cost: for what is waiting to be decided,
 * and for what has been approved. Hidden from anyone who may not see cost.
 */
class StockTakeTotals extends StatsOverviewWidget
{
    // Two to a row on a phone, so the counts are reached without a long scroll.
    protected int|array|null $columns = ['default' => 2, 'lg' => 3];

    protected static bool $isDiscovered = false;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    #[On('stock-take-updated')]
    public function refreshTotals(): void {}

    public static function canView(): bool
    {
        return ProductForm::canSeeCostPrice();
    }

    protected function getStats(): array
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;

        $sum = function (string $status) use ($stockTake) {
            $values = StockTakeResult::query()
                ->where('stock_take_id', $stockTake->id)
                ->where('status', $status)
                ->get()
                ->map(fn (StockTakeResult $r) => ResultsRelationManager::valueOf($r))
                ->filter(fn ($v) => $v !== null);

            return [
                'short' => abs($values->filter(fn ($v) => $v < 0)->sum()),
                'over'  => $values->filter(fn ($v) => $v > 0)->sum(),
            ];
        };

        $naira = fn (float $v) => '₦' . number_format($v, 2);
        $wait  = $sum(StockTakeResult::STATUS_PENDING);
        $done  = $sum(StockTakeResult::STATUS_APPROVED);
        $net   = fn (array $t) => $t['over'] - $t['short'];

        return [
            Stat::make('Shortage waiting', $naira($wait['short']))->color('danger')
                ->description('Approved: ' . $naira($done['short'])),
            Stat::make('Surplus waiting', $naira($wait['over']))->color('success')
                ->description('Approved: ' . $naira($done['over'])),
            Stat::make('Net waiting', ($net($wait) < 0 ? '−' : '') . $naira(abs($net($wait))))
                ->color($net($wait) < 0 ? 'danger' : 'success')
                ->description('Approved: ' . ($net($done) < 0 ? '−' : '') . $naira(abs($net($done)))),
        ];
    }
}
