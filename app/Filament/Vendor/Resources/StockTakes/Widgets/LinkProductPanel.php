<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeUnknownItem;
use App\Services\StockTake\StockTakePlanner;
use App\Services\StockTake\UnknownItemMatcher;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * "Link to existing" for a barcode the system did not know. The pop-up opens
 * at once with what the counter wrote (name, photo, barcode) beside the
 * likeliest products, each with its photo, SKU, price and stock here, so a
 * manager can compare and link in one click. Typing searches the rest. Only
 * this small component goes to the server: the lists behind it stay put.
 */
class LinkProductPanel extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.vendor.stock-take.link-product-panel';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public ?int $itemId = null;

    public string $search = '';

    public function show(int $id): void
    {
        $this->itemId = $this->item($id)?->id;
        $this->search = '';
    }

    /**
     * @param  bool  $asMain  the product already has a barcode: make this the
     *                        main one (the old stays as another) rather than
     *                        adding it as another
     */
    public function link(int $productId, bool $asMain = false): void
    {
        $item = $this->item($this->itemId);

        if (! $item) {
            return;
        }

        try {
            app(StockTakePlanner::class)->linkUnknown(
                auth()->user(),
                $item,
                Product::query()->where('vendor_id', $item->vendor_id)->findOrFail($productId),
                $asMain,
            );
        } catch (ValidationException $e) {
            Notification::make()->title('Not done')->body(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($asMain ? 'Barcode linked as the main one' : 'Barcode linked')
            ->success()
            ->send();
        $this->itemId = null;
        $this->dispatch('close-modal', id: 'gp-link-product');
        $this->dispatch('stock-take-updated');
    }

    /** Only this stock take's branch, and only one still waiting. */
    private function item(?int $id): ?StockTakeUnknownItem
    {
        if ($id === null) {
            return null;
        }

        /** @var StockTake $stockTake */
        $stockTake = $this->record;

        return StockTakeUnknownItem::query()
            ->where('vendor_id', $stockTake->vendor_id)
            ->where('store_id', $stockTake->store_id)
            ->open()
            ->with(['aisle', 'creator:id,name'])
            ->find($id);
    }

    protected function getViewData(): array
    {
        $item    = $this->item($this->itemId);
        $matcher = app(UnknownItemMatcher::class);
        $typed   = trim($this->search) !== '';

        return [
            'item'     => $item,
            'photo'    => $item?->photo_path ? Storage::disk('public')->url($item->photo_path) : null,
            'typed'    => $typed,
            'products' => $item ? ($typed ? $matcher->search($item, $this->search) : $matcher->likely($item)) : [],
        ];
    }
}
