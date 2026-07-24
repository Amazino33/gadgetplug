<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    // Custom Blade view — bypasses Filament's Table/EmbeddedTable rendering
    // entirely. That component's responsive column-stacking and its Table
    // config caching during the boot phase (before live property updates take
    // effect) fought a hand-designed layout too much to be worth patching
    // further; this page now drives its own query/pagination/selection.
    protected string $view = 'filament.vendor.pages.products-list';

    // Persisted via ?display=grid so a shared/bookmarked link keeps the chosen view.
    #[Url(as: 'display', keep: true)]
    public string $displayMode = 'table';

    #[Url(keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public ?string $statusFilter = null;

    /** @var array<int> */
    public array $selected = [];

    public function updatingSearch(): void
    {
        $this->resetLivewirePage('productsPage');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetLivewirePage('productsPage');
    }

    public function getProducts(): LengthAwarePaginator
    {
        return ProductResource::getEloquentQuery()
            ->when($this->search !== '', fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('sku', 'like', "%{$this->search}%")
            ))
            ->when($this->statusFilter, fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->latest()
            ->paginate(10, pageName: 'productsPage');
    }

    public function toggleSelected(int $id): void
    {
        $this->selected = in_array($id, $this->selected, true)
            ? array_values(array_diff($this->selected, [$id]))
            : [...$this->selected, $id];
    }

    public function toggleSelectAllOnPage(): void
    {
        $idsOnPage = $this->getProducts()->pluck('id')->all();
        $allSelected = count(array_intersect($idsOnPage, $this->selected)) === count($idsOnPage);

        $this->selected = $allSelected
            ? array_values(array_diff($this->selected, $idsOnPage))
            : array_values(array_unique([...$this->selected, ...$idsOnPage]));
    }

    public function deleteProduct(int $id): void
    {
        $product = Product::findOrFail($id);

        if (! ProductResource::canDelete($product)) {
            Notification::make()->title('You are not authorized to delete this product.')->danger()->send();
            return;
        }

        $product->delete();
        $this->selected = array_values(array_diff($this->selected, [$id]));

        Notification::make()->title('Product deleted.')->success()->send();
    }

    public function deleteSelected(): void
    {
        $products = Product::whereIn('id', $this->selected)->get();

        $deleted = 0;
        foreach ($products as $product) {
            if (ProductResource::canDelete($product)) {
                $product->delete();
                $deleted++;
            }
        }

        $this->selected = [];

        Notification::make()->title("{$deleted} product(s) deleted.")->success()->send();
    }

    protected function getHeaderActions(): array
    {
        // Real navigation links, not Livewire property-setting actions — Filament
        // caches the Table config during the request's boot phase, before an
        // in-place property update would take effect, so a live click never
        // sees the new displayMode in time. A fresh page load (URL already
        // carrying ?display=...) hydrates it correctly from the start.
        return [
            Action::make('tableView')
                ->label('Table')
                ->icon('heroicon-o-table-cells')
                ->color(fn (): string => $this->displayMode === 'table' ? 'primary' : 'gray')
                ->url(fn (): string => static::getUrl(parameters: ['display' => 'table'])),

            Action::make('gridView')
                ->label('Grid')
                ->icon('heroicon-o-squares-2x2')
                ->color(fn (): string => $this->displayMode === 'grid' ? 'primary' : 'gray')
                ->url(fn (): string => static::getUrl(parameters: ['display' => 'grid'])),

            CreateAction::make(),
        ];
    }
}
