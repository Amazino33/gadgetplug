<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Services\ActiveStore;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

/**
 * Types stock on hand straight from a vendor's spreadsheet.
 *
 * Products import at zero by design — a spreadsheet cannot say which branch it
 * means, so ProductImporter refuses to set stock (see ProductField::Quantity).
 * That leaves a real gap when a vendor arrives with a catalogue they already
 * know the numbers for: a procurement invents a supplier and a cost, and a full
 * blind count means walking the shelf for hours.
 *
 * This closes it without weakening the rule the importer protects. Every line
 * still moves through AdjustStockAction, against the store the user is currently
 * working in, and lands in the ledger as a 'stock_adjustment' with a reason
 * attached — so the opening balance is explained rather than merely present.
 *
 * The list mode covers the other half of the job: correcting products one at a
 * time without knowing their SKUs. It lists what this branch stocks and saves
 * each line the moment it is entered, through the same action and ledger type
 * as the paste.
 */
class StockAdjustment extends Page
{
    use WithPagination;

    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Stock Adjustment';
    protected static ?string $title           = 'Stock Adjustment';
    protected static ?int $navigationSort = 3;
    protected string $view = 'filament.vendor.pages.stock-adjustment';

    /**
     * Setting stock by hand bypasses both procurement and counting, so it sits
     * behind its own permission rather than riding on manage_inventory, which
     * every storekeeper holds.
     */
    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return false;
        }

        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'adjust_stock');
    }

    public const REASON_SHEET = 'Opening stock from vendor sheet';
    public const REASON_LIST  = 'Stock correction';

    public const PER_PAGE = 25;

    /** 'list' to pick products one by one, 'paste' for a spreadsheet block. */
    #[Url]
    public string $mode = 'list';

    /** Narrows the branch's products by name, SKU or barcode. */
    public string $search = '';

    /** @var array<int|string, mixed> typed shelf totals, keyed by product id */
    public array $counts = [];

    /** @var array<int, int> product id => change just saved, for the row's confirmation */
    public array $saved = [];

    /** Pasted rows: an identifier and a quantity per line. */
    public string $pasted = '';

    /** Why the stock is being set. Stored on every ledger row this creates. */
    public string $reason = self::REASON_LIST;

    /** @var array<int, array<string, mixed>> */
    public array $preview = [];

    public bool $hasPreviewed = false;

    /** Guards against a paste large enough to time the request out. */
    public const MAX_ROWS = 500;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Reads the pasted block without touching anything.
     *
     * Spreadsheets copy as tab-separated, humans type commas, and some paste
     * "SKU 12" with a space, so all three are accepted. Quantities are absolute:
     * the sheet says what the shelf holds, not how much to add.
     */
    public function buildPreview(): void
    {
        $this->hasPreviewed = true;
        $this->preview      = [];

        $vendor = filament()->getTenant();
        $lines  = preg_split('/\r\n|\r|\n/', trim($this->pasted)) ?: [];
        $lines  = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

        if ($lines === []) {
            Notification::make()->title('Nothing pasted yet.')->warning()->send();
            return;
        }

        if (count($lines) > self::MAX_ROWS) {
            Notification::make()
                ->title('That is a lot of rows')
                ->body('Paste at most ' . self::MAX_ROWS . ' lines at a time so the page does not time out. ' . count($lines) . ' were pasted.')
                ->danger()
                ->send();
            return;
        }

        // One query for the whole paste rather than one per line
        $keys     = array_map(fn ($l) => $this->splitLine($l)[0], $lines);
        $products = $this->lookup($vendor->id, array_filter($keys));

        $seen = [];

        foreach ($lines as $line) {
            [$key, $qtyRaw] = $this->splitLine($line);

            if ($key === '' || $qtyRaw === null || ! is_numeric($qtyRaw)) {
                $this->preview[] = $this->row($line, null, null, 'Could not read this line');
                continue;
            }

            $qty = (int) $qtyRaw;

            if ($qty < 0) {
                $this->preview[] = $this->row($line, null, null, 'Quantity cannot be negative');
                continue;
            }

            $product = $products->get(mb_strtolower($key));

            if (! $product) {
                $this->preview[] = $this->row($line, null, $qty, 'No product with that SKU or barcode');
                continue;
            }

            if (isset($seen[$product->id])) {
                $this->preview[] = $this->row($line, $product, $qty, 'Listed more than once — only the first is applied');
                continue;
            }

            $seen[$product->id] = true;
            $this->preview[]    = $this->row($line, $product, $qty, null);
        }

        $ok = collect($this->preview)->where('error', null)->count();
        $bad = count($this->preview) - $ok;

        Notification::make()
            ->title("{$ok} ready to apply" . ($bad > 0 ? ", {$bad} need attention" : ''))
            ->body($bad > 0 ? 'Lines with a problem are skipped when you apply.' : 'Check the numbers, then apply.')
            ->{$bad > 0 ? 'warning' : 'success'}()
            ->send();
    }

    /**
     * Applies only the lines that resolved cleanly, one AdjustStockAction each.
     *
     * Deliberately not wrapped in a single transaction: each line is already
     * atomic and independently ledgered, and on a 500-line paste one bad row
     * should not silently undo the 499 that worked.
     */
    public function apply(AdjustStockAction $adjust): void
    {
        abort_unless(static::canAccess(), 403);

        if (! $this->hasPreviewed) {
            Notification::make()->title('Preview the list first so you can see what will change.')->warning()->send();
            return;
        }

        if (trim($this->reason) === '') {
            $this->addError('reason', 'Give a reason — it is stored against every stock movement this creates.');
            return;
        }

        $storeId  = ActiveStore::currentId();
        $applied  = 0;
        $skipped  = 0;
        $failed   = [];

        foreach ($this->preview as $row) {
            if ($row['error'] !== null || $row['product_id'] === null) {
                $skipped++;
                continue;
            }

            // Nothing to do when the sheet already agrees with the system
            if ((int) $row['change'] === 0) {
                $skipped++;
                continue;
            }

            try {
                $adjust->execute(
                    productId:       (int) $row['product_id'],
                    quantityChanged: (int) $row['change'],
                    transactionType: 'stock_adjustment',
                    userId:          auth()->id(),
                    reference:       'Stock adjustment',
                    description:     trim($this->reason),
                    store:           $storeId,
                );
                $applied++;
            } catch (\Throwable $e) {
                $failed[] = $row['name'] . ': ' . $e->getMessage();
            }
        }

        if ($failed !== []) {
            Notification::make()
                ->title(count($failed) . ' line(s) could not be applied')
                ->body(implode(' | ', array_slice($failed, 0, 3)))
                ->danger()
                ->send();
        }

        Notification::make()
            ->title("Stock updated for {$applied} product(s)")
            ->body($skipped > 0 ? "{$skipped} line(s) skipped — already correct, or had a problem." : 'Every line applied.')
            ->success()
            ->send();

        // Re-read from the database so the screen shows the new reality
        $this->buildPreview();
    }

    /**
     * Swaps modes, and the stock reason with it, but only while the user has
     * not written one of their own.
     */
    public function setMode(string $mode): void
    {
        if (! in_array($mode, ['list', 'paste'], true)) {
            return;
        }

        if ($this->reason === $this->defaultReason($this->mode)) {
            $this->reason = $this->defaultReason($mode);
        }

        $this->mode = $mode;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * The branch's own stock rows, zeroes included: a product this branch has
     * sold out of still belongs to it and is exactly what gets corrected.
     * A vendor with no stores yet has no branch rows, so it sees its whole
     * catalogue against the vendor-wide figure, as currentStock() does.
     */
    public function getProducts(): Paginator
    {
        $storeId = ActiveStore::currentId();
        $term    = trim($this->search);

        $query = Product::query()
            ->where('products.vendor_id', filament()->getTenant()->id)
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('products.name', 'like', "%{$term}%")
                ->orWhere('products.sku', 'like', "%{$term}%")
                ->orWhere('products.barcode', 'like', "%{$term}%")))
            ->orderBy('products.name')
            ->orderBy('products.id');

        if ($storeId === null) {
            return $query
                ->select(['products.id', 'products.name', 'products.sku', 'products.barcode', 'products.stock_quantity as on_hand'])
                ->simplePaginate(self::PER_PAGE);
        }

        return $query
            ->join('product_store_stock as pss', fn ($j) => $j
                ->on('pss.product_id', '=', 'products.id')
                ->where('pss.store_id', $storeId))
            ->select(['products.id', 'products.name', 'products.sku', 'products.barcode', 'pss.quantity as on_hand'])
            ->simplePaginate(self::PER_PAGE);
    }

    /**
     * Saves one line from the list straight away. The typed figure is the
     * shelf total, as in the paste, so the change is worked out here against
     * the branch's current row rather than trusted from the screen, which may
     * be stale by a sale or two.
     */
    public function setStock(int $productId, AdjustStockAction $adjust): void
    {
        abort_unless(static::canAccess(), 403);

        $raw = trim((string) ($this->counts[$productId] ?? ''));

        if ($raw === '' || ! ctype_digit($raw)) {
            $this->addError("counts.{$productId}", 'Enter a whole number, 0 or more.');
            return;
        }

        if (trim($this->reason) === '') {
            $this->addError('reason', 'Give a reason — it is stored against every stock movement this creates.');
            return;
        }

        $storeId = ActiveStore::currentId();

        // Only this vendor's products, and only those this branch stocks: the
        // list never offers anything else, so neither does the action.
        $product = Product::query()
            ->where('vendor_id', filament()->getTenant()->id)
            ->when($storeId !== null, fn ($q) => $q->whereHas('storeStocks', fn ($s) => $s->where('store_id', $storeId)))
            ->find($productId);

        if (! $product) {
            Notification::make()->title('That product is not stocked in ' . $this->getStoreName() . '.')->danger()->send();
            return;
        }

        $change = (int) $raw - $this->currentStock($product);

        $this->resetErrorBag("counts.{$productId}");

        if ($change !== 0) {
            try {
                $adjust->execute(
                    productId:       $product->id,
                    quantityChanged: $change,
                    transactionType: 'stock_adjustment',
                    userId:          auth()->id(),
                    reference:       'Stock adjustment',
                    description:     trim($this->reason),
                    store:           $storeId,
                );
            } catch (\Throwable $e) {
                Notification::make()->title("{$product->name} could not be updated")->body($e->getMessage())->danger()->send();
                return;
            }
        }

        unset($this->counts[$productId]);
        $this->saved[$productId] = $change;
        $this->dispatch('stock-line-saved', id: $productId);
    }

    private function defaultReason(string $mode): string
    {
        return $mode === 'paste' ? self::REASON_SHEET : self::REASON_LIST;
    }

    public function clearAll(): void
    {
        $this->pasted       = '';
        $this->preview      = [];
        $this->hasPreviewed = false;
    }

    public function getStoreName(): string
    {
        $id = ActiveStore::currentId();

        return $id
            ? (\App\Models\Store::find($id)?->name ?? 'this store')
            : 'the default store';
    }

    /** @return array{0: string, 1: string|null} */
    private function splitLine(string $line): array
    {
        // Tab first (spreadsheet paste), then comma or semicolon, then whitespace
        $parts = preg_split('/\t|,|;|\s{2,}| (?=\S+$)/', $line) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));

        if (count($parts) < 2) {
            return [$parts[0] ?? '', null];
        }

        // Quantity is the last field; the identifier may itself contain spaces
        $qty = array_pop($parts);

        return [implode(' ', $parts), $qty];
    }

    /** @return Collection<string, Product> keyed by lowercase sku and barcode */
    private function lookup(int $vendorId, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        $products = Product::query()
            ->where('vendor_id', $vendorId)
            ->where(fn ($q) => $q->whereIn('sku', $keys)->orWhereIn('barcode', $keys))
            ->get(['id', 'name', 'sku', 'barcode', 'stock_quantity']);

        $keyed = collect();

        foreach ($products as $product) {
            if ($product->sku) {
                $keyed->put(mb_strtolower($product->sku), $product);
            }
            if ($product->barcode) {
                $keyed->put(mb_strtolower($product->barcode), $product);
            }
        }

        return $keyed;
    }

    /** @return array<string, mixed> */
    private function row(string $line, ?Product $product, ?int $target, ?string $error): array
    {
        $current = $product ? $this->currentStock($product) : null;

        return [
            'line'       => $line,
            'product_id' => $product?->id,
            'name'       => $product?->name ?? $line,
            'sku'        => $product?->sku ?? '',
            'current'    => $current,
            'target'     => $target,
            'change'     => ($product && $target !== null) ? $target - $current : null,
            'error'      => $error,
        ];
    }

    /**
     * What this branch's own row shows — not the vendor-wide mirror. The
     * sheet's number is an absolute target for the store being worked in
     * (see buildPreview()'s docblock), so measuring it against every
     * branch's stock combined would compute the wrong delta and apply()
     * would then move that wrong amount, correctly, to the right store.
     */
    private function currentStock(Product $product): int
    {
        $storeId = ActiveStore::currentId();

        if ($storeId === null) {
            return (int) $product->stock_quantity;
        }

        return (int) (ProductStoreStock::where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->value('quantity') ?? 0);
    }
}
