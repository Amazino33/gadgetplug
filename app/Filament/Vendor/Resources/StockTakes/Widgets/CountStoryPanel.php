<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\StockTake;
use App\Models\StockTakeResult;
use App\Services\StockTake\StockTakeStory;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * "What happened" behind a count, opened from the numbers on the results
 * list (and the phone card). The panel slides in at once with a loading
 * sign, and only this small component goes to the server for the story:
 * the results list is not drawn again for it.
 */
class CountStoryPanel extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.vendor.stock-take.count-story-panel';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public ?int $resultId = null;

    /** Loads one count's story, only ever one of this stock take's. */
    public function show(int $id): void
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;

        $this->resultId = StockTakeResult::query()
            ->where('stock_take_id', $stockTake->id)
            ->whereKey($id)
            ->value('id');
    }

    protected function getViewData(): array
    {
        $result = $this->resultId === null ? null : StockTakeResult::query()
            ->where('stock_take_id', $this->record->id)
            ->find($this->resultId);

        return [
            'story' => $result ? app(StockTakeStory::class)->for($result) : null,
        ];
    }
}
