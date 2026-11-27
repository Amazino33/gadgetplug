<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\StockTake;
use App\Services\StockTake\TillSync;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * "Tills at this branch still hold unsynced sales." Approving now may later
 * need a Re-check, when a sale rung before a count arrives.
 */
class TillSyncWarning extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.vendor.stock-take.till-warning';

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    #[On('stock-take-updated')]
    public function refreshWarning(): void {}

    protected function getViewData(): array
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;

        return ['tills' => TillSync::pendingAt((int) $stockTake->store_id)];
    }
}
