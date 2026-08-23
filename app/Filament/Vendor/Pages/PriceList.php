<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class PriceList extends Page
{
    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-tag';
    protected static string|null|UnitEnum   $navigationGroup = 'Products';
    protected static ?string $navigationLabel = 'Price List';
    protected static ?string $title           = 'Price List';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.vendor.pages.price-list';

    // Deliberately the broadest product permission: this is a reference sheet
    // every staff member needs, and it carries selling prices only.
    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user->hasVendorPermission($vendor->id, 'view_products');
    }

    // On-screen filter only. The PDF is always the complete list — a partial
    // pricelist saved to someone's phone would be worse than none.
    public string $search = '';

    /**
     * Published products grouped by category name.
     *
     * Cost price is never selected. This sheet is built to be forwarded and
     * screenshotted, so margin data must not be in the query at all, let alone
     * the markup.
     */
    public function getGroupedProducts(bool $applySearch = false): Collection
    {
        $vendor = filament()->getTenant();

        return Product::query()
            ->published()
            ->where('vendor_id', $vendor->id)
            ->when($applySearch && $this->search !== '', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('sku', 'like', "%{$this->search}%")
                    ->orWhere('brand', 'like', "%{$this->search}%")))
            ->with('category:id,name')
            ->select(['id', 'name', 'price', 'stock_quantity', 'category_id', 'sku', 'brand'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Product $p) => $p->category?->name ?: 'Uncategorised')
            ->sortKeys();
    }

    public function getProductCount(): int
    {
        return Product::published()->where('vendor_id', filament()->getTenant()->id)->count();
    }

    /**
     * Column capacity in "line units", calibrated against dompdf on A4.
     * One single-line product row = 1 unit; a category heading = 1.6.
     *
     * Measured empirically: 75 is the largest value that still fits one physical
     * page for both short and wrapping names; 80 overflows both. 72 leaves a
     * margin, since the characters-per-line estimate is approximate.
     *
     * Roughly 216 products per A4 page with short names, ~108 with long ones.
     * Re-run the calibration if the font size or page margins change.
     */
    public const COLUMN_CAPACITY = 72.0;

    public const COLUMNS_PER_PAGE = 3;

    /** Name characters that fit on one line of a column at the sheet's font size. */
    private const CHARS_PER_LINE = 30;

    /** A long name wraps, but never lets one product dominate a column. */
    private const MAX_NAME_LINES = 2;

    // Row heights are not uniform — a long product name wraps and costs twice as
    // much vertical space as a short one. Packing columns by row *count* either
    // overflows the page on long names or wastes half of it on short ones, so
    // columns are packed by estimated height instead.
    private function estimateLines(string $name): int
    {
        $lines = (int) ceil(mb_strlen($name) / self::CHARS_PER_LINE);

        return max(1, min($lines, self::MAX_NAME_LINES));
    }

    /**
     * Greedily fills columns up to a height budget, repeating a category heading
     * as "(cont.)" wherever one spills over, and never leaving a heading stranded
     * at the foot of a column with its products in the next one.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function packColumns(array $rows, float $capacity): array
    {
        $columns         = [];
        $current         = [];
        $used            = 0.0;
        $currentCategory = null;

        foreach ($rows as $row) {
            // A heading alone at the foot of a column would orphan its products
            $needed = $row['units'] + ($row['type'] === 'header' ? 1.0 : 0.0);

            if ($current !== [] && $used + $needed > $capacity) {
                $columns[] = $current;
                $current   = [];
                $used      = 0.0;

                if ($row['type'] === 'item' && $currentCategory !== null) {
                    $carried   = ['type' => 'header', 'text' => $currentCategory . ' (cont.)', 'units' => 1.6];
                    $current[] = $carried;
                    $used     += $carried['units'];
                }
            }

            if ($row['type'] === 'header') {
                $currentCategory = $row['text'];
            }

            $current[] = $row;
            $used     += $row['units'];
        }

        if ($current !== []) {
            $columns[] = $current;
        }

        return $columns;
    }

    /**
     * Turns the grouped products into explicit pages of fixed-height columns.
     *
     * Pagination is done here rather than left to the PDF engine on purpose.
     * dompdf splits a table row that overflows a page very badly — a single tall
     * <tr> holding three column cells came out as a blank page, then one lone
     * column, then all three, then a page holding only the footer. Emitting one
     * self-contained table per page, with an explicit page break between them,
     * means no row ever has to split and the layout is identical every time.
     *
     * A column that opens mid-category repeats its heading with "(cont.)", so a
     * column never starts with prices belonging to no visible heading.
     *
     * @return array<int, array<int, array<int, array{type: string, text?: string, name?: string, price?: string, out?: bool}>>>
     */
    public function buildPages(Collection $grouped, float $capacity = self::COLUMN_CAPACITY, int $columnsPerPage = self::COLUMNS_PER_PAGE): array
    {
        $rows = [];

        foreach ($grouped as $categoryName => $products) {
            $rows[] = ['type' => 'header', 'text' => $categoryName, 'units' => 1.6];

            foreach ($products as $product) {
                $rows[] = [
                    'type'  => 'item',
                    'name'  => $product->name,
                    'price' => number_format((float) $product->price, 2),
                    'out'   => (int) $product->stock_quantity <= 0,
                    'units' => (float) $this->estimateLines((string) $product->name),
                ];
            }
        }

        if ($rows === []) {
            return [];
        }

        $columns = $this->packColumns($rows, $capacity);

        // When the whole list fits on one sheet, spread it evenly across the
        // three columns instead of filling the first to capacity and leaving the
        // other two blank — which is what most stores' catalogues would do.
        // Carried and orphan-avoiding headings consume space the even split
        // cannot predict, so grow the target until it really does fit one page.
        if (count($columns) <= $columnsPerPage) {
            $totalUnits = array_sum(array_column($rows, 'units'));
            $balanced   = max(1.0, ceil($totalUnits / $columnsPerPage));

            for ($try = $balanced; $try <= $capacity; $try++) {
                $candidate = $this->packColumns($rows, (float) $try);

                if (count($candidate) <= $columnsPerPage) {
                    $columns = $candidate;
                    break;
                }
            }
        }

        $pages = array_chunk($columns, $columnsPerPage);

        // Pad the final page so its columns keep their width
        $last = count($pages) - 1;
        $pages[$last] = array_pad($pages[$last], $columnsPerPage, []);

        return $pages;
    }

    public function downloadPdf(): StreamedResponse
    {
        $vendor  = filament()->getTenant();
        $grouped = $this->getGroupedProducts();

        $pdf = Pdf::loadView('filament.vendor.pages.price-list-pdf', [
            'vendor'      => $vendor,
            'pages'       => $this->buildPages($grouped),
            'total'       => $grouped->flatten()->count(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = str($vendor->name)->slug() . '-pricelist-' . now()->format('Y-m-d') . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }
}
