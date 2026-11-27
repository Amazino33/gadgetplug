<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\StockTake;
use App\Services\StockTake\StockTakeAccuracy;
use App\Support\Quantity;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * How each counter's counts held up when someone else counted the same
 * product again (a recount or a spot-check), in this stock take and across
 * the branch. Shown once there is at least one second count.
 */
class CounterAccuracy extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.vendor.stock-take.counter-accuracy';

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    #[On('stock-take-updated')]
    public function refreshList(): void {}

    public static function canView(): bool
    {
        return true;
    }

    protected function getViewData(): array
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;
        $accuracy  = app(StockTakeAccuracy::class);

        $format = fn ($rows) => $rows->map(fn (array $r) => $r + ['off_text' => Quantity::format($r['off_by'])])->all();

        return [
            'here'   => $format($accuracy->forStockTake($stockTake->id)),
            'branch' => $format($accuracy->forStore((int) $stockTake->vendor_id, (int) $stockTake->store_id)),
        ];
    }
}
