<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Pages\HelpCenter;
use App\Filament\Vendor\Pages\ImportProducts;
use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Category;
use App\Models\Product;
use App\Services\ActiveStore;
use App\Services\Export\ProductExporter;
use App\Services\Products\ProductCatalogue;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Services\Products\QuickProductForm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use App\Support\ProductSearch;
use App\Services\Products\SearchShortcuts;

class ListProducts extends ListRecords
{
    /**
     * Rows rendered with the page, so the list shows at once. The whole
     * branch then arrives in the background (ProductCatalogue) and the page
     * searches, filters, sorts and pages it in the browser.
     */
    public const FIRST_PAGE = 50;

    /** Fields the list can change for many products at once. */
    private const BULK_FIELDS = ['status', 'category_id', 'low_stock_threshold'];

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

    /**
     * New photos from the product pop-up, uploaded the moment Save is pressed
     * and read by saveQuickProduct() straight after.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $quickPhotos = [];

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
                // Every typed word, in any order: "peak 400" finds PEAK MILK
                // TIN 400G (see App\Support\ProductSearch).
                fn (Builder $q) => $q->where(fn (Builder $words) => ProductSearch::apply($words, $this->search, ['products.name', 'products.sku', 'products.brand', 'products.search_keywords']))
                    ->orWhereIn('products.id', SearchShortcuts::productIdsMatching((int) filament()->getTenant()->id, $this->search))
                    // A scanned barcode finds the product too: its main code,
                    // an extra code, or a carton's own.
                    ->orWhere('products.barcode', 'like', "%{$this->search}%")
                    ->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', 'like', "%{$this->search}%"))
            ))
            ->when($this->statusFilter, fn (Builder $query) => $query->where('status', $this->statusFilter))
            // Column-qualified: getEloquentQuery() already joins for the
            // per-store stock columns, so a bare category_id is ambiguous.
            ->when($this->categoryFilter, fn (Builder $query) => $query->where('products.category_id', $this->categoryFilter))
            ->latest('products.id')
            // Each row shows its category: loaded with the page, not one by one.
            ->with('category:id,name')
            ->paginate(self::FIRST_PAGE, pageName: 'productsPage');
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
        $this->dispatch('products-changed');
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

    // ── The list in the browser ─────────────────────────────────────────

    /** What the list needs to start: where to fetch the rest, what this person may do, the first rows. */
    public function getListConfig(): array
    {
        $vendor   = filament()->getTenant();
        $user     = auth()->user();
        $storeId  = ActiveStore::currentId();
        $withCost = $this->canSeeCostPrice();
        $first    = $this->getProducts();

        return [
            'catalogueUrl' => route('vendor.products.catalogue', ['vendor' => $vendor->slug]),
            'storageKey'   => "gadgetplug.products.{$user->id}.{$vendor->id}." . ($storeId ?? 'all'),
            'viewUrl'      => ProductResource::getUrl('view', ['record' => '__ID__'], tenant: $vendor),
            'editUrl'      => ProductResource::getUrl('edit', ['record' => '__ID__'], tenant: $vendor),
            'halves'       => Quantity::allowsHalves($vendor),
            'can'          => [
                'cost'   => $withCost,
                'edit'   => $user->isSuperAdmin() || $vendor->isOwner($user) || $user->hasVendorPermission($vendor->id, 'edit_products'),
                'delete' => $user->isSuperAdmin() || $vendor->isOwner($user) || $user->hasVendorPermission($vendor->id, 'delete_products'),
                'export' => static::canBulkMove('export_products'),
                'create' => ProductResource::canCreate(),
            ],
            'columns'      => ProductCatalogue::COLUMNS,
            'initial'      => [
                'rows'       => app(ProductCatalogue::class)->rowsFor($first->getCollection(), $storeId, $withCost),
                'total'      => $first->total(),
                'categories' => $this->getCategoryOptions(),
            ],
            // From the address: a shared link opens on the same view.
            'url'          => [
                'display'  => $this->displayMode,
                'search'   => $this->search,
                'status'   => $this->statusFilter,
                'category' => $this->categoryFilter,
            ],
        ];
    }

    /**
     * Change one field on many products at once: status, category, or the
     * level at which a product counts as running low.
     *
     * @param  array<int, int>  $ids
     * @return array{ok: bool, changed?: int, message?: string}
     */
    #[Renderless]
    public function bulkUpdate(array $ids, string $field, mixed $value): array
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        if (! ($user->isSuperAdmin() || $vendor->isOwner($user) || $user->hasVendorPermission($vendor->id, 'edit_products'))) {
            return ['ok' => false, 'message' => 'You are not allowed to change products.'];
        }

        if (! in_array($field, self::BULK_FIELDS, true)) {
            return ['ok' => false, 'message' => 'That cannot be changed in bulk.'];
        }

        $value = match ($field) {
            'status'              => in_array($value, ['draft', 'published', 'archived'], true) ? $value : null,
            'category_id'         => DB::table('categories')->where('id', (int) $value)->exists() ? (int) $value : null,
            'low_stock_threshold' => is_numeric($value) && (int) $value >= 0 && (int) $value <= 1000000 ? (int) $value : null,
        };

        if ($value === null) {
            return ['ok' => false, 'message' => 'That value is not allowed.'];
        }

        $changed = 0;
        foreach ($this->ownProducts($ids) as $product) {
            $product->update([$field => $value]);
            $changed++;
        }

        Notification::make()->title("{$changed} product(s) updated.")->success()->send();
        $this->dispatch('products-changed');

        return ['ok' => true, 'changed' => $changed];
    }

    /**
     * Delete many products at once (each one checked).
     *
     * @param  array<int, int>  $ids
     */
    #[Renderless]
    public function bulkDelete(array $ids): array
    {
        $deleted = 0;
        foreach ($this->ownProducts($ids) as $product) {
            if (ProductResource::canDelete($product)) {
                $product->delete();
                $deleted++;
            }
        }

        Notification::make()->title("{$deleted} product(s) deleted.")->success()->send();
        $this->dispatch('products-changed');

        return ['ok' => true, 'deleted' => $deleted];
    }

    /**
     * Export just these products, in the same file as Export.
     *
     * @param  array<int, int>  $ids
     */
    public function exportSelected(array $ids, string $format = 'csv')
    {
        if (! static::canBulkMove('export_products')) {
            Notification::make()->title('You are not allowed to export products.')->danger()->send();

            return null;
        }

        $format = in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv';
        $own    = $this->ownProducts($ids)->pluck('id')->all();
        $path   = app(ProductExporter::class)->export(filament()->getTenant(), $format, ['ids' => $own ?: [0]]);

        return response()->download($path, 'products-' . now()->format('Y-m-d') . ".{$format}")->deleteFileAfterSend();
    }

    /**
     * Of these ids, the ones this list shows: this shop's, at this branch.
     *
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function ownProducts(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }

        $visible = ProductResource::getEloquentQuery()->whereIn('products.id', $ids)->pluck('products.id')->all();

        return Product::whereIn('id', $visible ?: [0])->get();
    }

    // ── The product pop-up ──────────────────────────────────────────────
    // The form itself lives in the browser (products/quick-form.blade.php);
    // these are the only times it asks the server anything.

    /** What the pop-up needs to know about this shop, sent with the page. */
    public function getQuickFormOptions(): array
    {
        return app(QuickProductForm::class)->options(filament()->getTenant());
    }

    /** A product to edit in the pop-up. */
    #[Renderless]
    public function loadQuickProduct(int $id): array
    {
        $product = $this->editableProduct($id);

        return $product
            ? ['ok' => true, 'product' => app(QuickProductForm::class)->load($product)]
            : ['ok' => false, 'message' => 'This product could not be opened. It may have been deleted, or moved to another branch.'];
    }

    /**
     * Saves the pop-up: a new product when $id is null, else that one.
     *
     * Refusals come back as data rather than an exception, so the pop-up can
     * put each one beside its field.
     */
    public function saveQuickProduct(array $data, ?int $id = null): array
    {
        $vendor = filament()->getTenant();
        $product = null;

        if ($id !== null) {
            $product = $this->editableProduct($id);

            if (! $product) {
                return ['ok' => false, 'errors' => ['form' => ['You cannot edit this product, or it no longer exists.']]];
            }
        } elseif (! ProductResource::canCreate()) {
            return ['ok' => false, 'errors' => ['form' => ['You are not allowed to add products.']]];
        }

        try {
            $saved = app(QuickProductForm::class)->save($vendor, $product, $data, array_values($this->quickPhotos));
        } catch (ValidationException $e) {
            return ['ok' => false, 'errors' => $e->errors()];
        }

        $this->quickPhotos = [];
        $this->dispatch('products-changed');

        Notification::make()
            ->title($id ? "{$saved->name} saved." : "{$saved->name} added.")
            ->body($id ? null : "SKU {$saved->sku}")
            ->success()
            ->send();

        return ['ok' => true, 'id' => $saved->id, 'name' => $saved->name, 'sku' => $saved->sku];
    }

    /** The SKU a new product would get, shown while the form is filled in. */
    #[Renderless]
    public function previewQuickSku(?string $name, $categoryId = null, ?int $id = null): ?string
    {
        return app(QuickProductForm::class)->previewSku(filament()->getTenant(), $name, $categoryId, $id);
    }

    /** Why a barcode is already taken, checked as someone leaves the field. */
    #[Renderless]
    public function checkQuickBarcode(string $code, ?int $id = null): ?string
    {
        return app(QuickProductForm::class)->barcodeProblem(filament()->getTenant(), $code, $id);
    }

    /** This shop's product in this branch, if the user may edit it. */
    private function editableProduct(int $id): ?Product
    {
        $visible = ProductResource::getEloquentQuery()->where('products.id', $id)->exists();
        $product = $visible ? Product::find($id) : null;

        return $product && ProductResource::canEdit($product) ? $product : null;
    }

    protected function getHeaderActions(): array
    {
        // Table / Grid is a switch in the list's own toolbar now: the list
        // lives in the browser, so it changes view instantly with no reload.
        return [
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
                            'category_id' => $data['category_id'] ?? null,
                            'status' => $data['status'] ?? null,
                            'low_stock_only' => (bool) ($data['low_stock_only'] ?? false),
                        ],
                    );

                    return response()->download($path)->deleteFileAfterSend();
                }),

            Action::make('import')
                ->label('Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                // Not offered on the free plan: the import page is outside it,
                // and a spreadsheet of hundreds would only run into the cap.
                ->visible(fn (): bool => static::canBulkMove('import_products') && ! filament()->getTenant()?->isFreePlan())
                ->url(fn (): string => ImportProducts::getUrl()),

            // Opens the pop-up form already on the page: no request, no wait.
            // The full create page (ProductResource 'create') still works for
            // anyone who goes to it directly.
            Action::make('create')
                ->label('New product')
                ->color('primary')
                ->visible(fn (): bool => ProductResource::canCreate())
                ->alpineClickHandler("window.dispatchEvent(new CustomEvent('product-form-open'))")
                // Tour hook: the "Add a product" tour points a vendor here.
                ->extraAttributes(['data-tour' => 'new-product']),

            HelpCenter::tourAction('add-product'),

            HelpCenter::helpAction('how-do-i-add-a-product'),
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
        $user = auth()->user();

        if ($vendor === null || $user === null) {
            return false;
        }

        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, $permission);
    }
}
