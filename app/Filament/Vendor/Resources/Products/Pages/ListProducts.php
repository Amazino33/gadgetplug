<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Pages\ImportProducts;
use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Category;
use App\Models\Product;
use App\Services\Export\ProductExporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
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

    #[Url(as: 'category', keep: true)]
    public ?string $categoryFilter = null;

    /** @var array<int> */
    public array $selected = [];

    public function canSeeCostPrice(): bool
    {
        return ProductForm::canSeeCostPrice();
    }

    public function updatingSearch(): void
    {
        $this->resetLivewirePage('productsPage');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetLivewirePage('productsPage');
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetLivewirePage('productsPage');
    }

    /**
     * Categories this branch actually stocks something in, for the filter.
     *
     * Deliberately not every category on the platform: those are shared across
     * all vendors, so listing them all would offer a shop selling phones a
     * filter for groceries that can only ever return nothing. Derived from the
     * same scoped query the list itself uses, so the options and the results
     * can never disagree.
     *
     * @return array<int, string>
     */
    public function getCategoryOptions(): array
    {
        return Category::query()
            ->whereIn('id', ProductResource::getEloquentQuery()
                ->select('products.category_id')
                ->distinct())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function getProducts(): LengthAwarePaginator
    {
        return ProductResource::getEloquentQuery()
            ->when($this->search !== '', fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('sku', 'like', "%{$this->search}%")
            ))
            ->when($this->statusFilter, fn (Builder $query) => $query->where('status', $this->statusFilter))
            // Column-qualified: getEloquentQuery() already joins for the
            // per-store stock columns, so a bare category_id is ambiguous.
            ->when($this->categoryFilter, fn (Builder $query) => $query->where('products.category_id', $this->categoryFilter))
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

            // Export sits before Import in the bar: taking a copy of what you
            // have is the safe first move before any bulk change.
            Action::make('export')
                ->label('Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => static::canBulkMove('export_products'))
                ->modalHeading('Export your products')
                ->modalDescription('Edit the file offline and import it back. Every field it carries comes back in, except stock.')
                ->modalSubmitActionLabel('Download')
                ->schema([
                    Select::make('format')
                        ->label('File type')
                        ->options(['csv' => 'CSV', 'xlsx' => 'Excel (.xlsx)'])
                        ->default('csv')
                        ->required(),

                    Select::make('category_id')
                        ->label('Only this category')
                        ->options(fn (): array => Category::orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->placeholder('All categories'),

                    Select::make('status')
                        ->label('Only this status')
                        ->options(['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'])
                        ->placeholder('Any status'),

                    Toggle::make('low_stock_only')
                        ->label('Only products running low'),
                ])
                ->action(function (array $data) {
                    $path = app(ProductExporter::class)->export(
                        filament()->getTenant(),
                        $data['format'] ?? 'csv',
                        [
                            'category_id'    => $data['category_id'] ?? null,
                            'status'         => $data['status'] ?? null,
                            'low_stock_only' => (bool) ($data['low_stock_only'] ?? false),
                        ],
                    );

                    return response()->download($path)->deleteFileAfterSend();
                }),

            Action::make('import')
                ->label('Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => static::canBulkMove('import_products'))
                ->url(fn (): string => ImportProducts::getUrl()),

            CreateAction::make(),
        ];
    }

    /**
     * Bulk catalogue movement is gated separately from editing products one at
     * a time. One import can rewrite every product a vendor has, which is a
     * larger act of trust than editing them individually.
     */
    protected static function canBulkMove(string $permission): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        if ($vendor === null || $user === null) {
            return false;
        }

        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, $permission);
    }
}
