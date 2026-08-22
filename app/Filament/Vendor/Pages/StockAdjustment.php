<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Product;
use App\Services\ActiveStore;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
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
 */
class StockAdjustment extends Page
{
    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Stock Adjustment';
    protected static ?string $title           = 'Stock Adjustment';
    protected static ?int    $navigationSort  = 4;
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

    /** Pasted rows: an identifier and a quantity per line. */
    public string $pasted = '';

    /** Why the stock is being set. Stored on every ledger row this creates. */
    public string $reason = 'Opening stock from vendor sheet';

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
        $current = $product ? (int) $product->stock_quantity : null;

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
}
