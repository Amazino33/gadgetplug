<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Product;
use App\Models\Vendor;
use App\Support\Import\ProductField;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes a vendor's catalogue out in the same shape the import reads back in.
 *
 * The column list comes from ProductField, not from a list kept here, so export
 * and import cannot drift apart. That is what makes the round trip safe: a
 * vendor exports, edits in Excel, re-imports, and every field they did not touch
 * comes back unchanged.
 *
 * The one deliberate exception is Quantity, which is written for the vendor to
 * read and ignored on the way back in - stock moves only through the ledger.
 */
class ProductExporter
{
    /** Rows held in memory at once while streaming out. */
    private const CHUNK = 500;

    /**
     * @param  array{category_id?: int|null, status?: string|null, low_stock_only?: bool}  $filters
     * @return string  absolute path to the written file
     */
    public function export(Vendor $vendor, string $format = 'csv', array $filters = [], ?string $path = null): string
    {
        $path ??= $this->temporaryPath($vendor, $format);

        $this->write($path, $format, function (callable $writeRow) use ($vendor, $filters) {
            $this->query($vendor, $filters)->chunkById(self::CHUNK, function ($products) use ($writeRow) {
                foreach ($products as $product) {
                    $writeRow($this->rowFor($product));
                }
            });
        });

        return $path;
    }

    /**
     * The pre-import safety copy. Always CSV, always the full unfiltered
     * catalogue - a partial snapshot would restore a partial catalogue.
     */
    public function snapshot(Vendor $vendor): string
    {
        $directory = storage_path('app/import-snapshots');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/vendor-'.$vendor->id.'-'.now()->format('Y-m-d-His').'.csv';

        return $this->export($vendor, 'csv', [], $path);
    }

    /**
     * A blank file with the headers and one worked example, for vendors with
     * nothing to export from.
     *
     * The example row is real enough to be copied: it shows how a yes/no column
     * should read and that Quantity is not filled in here.
     */
    public function template(string $format = 'csv'): string
    {
        $path = $this->temporaryPath(null, $format, 'gadgetplug-product-template');

        $this->write($path, $format, function (callable $writeRow) {
            $writeRow(collect(ProductField::exportColumns())->map(fn (ProductField $f) => match ($f) {
                ProductField::Name              => 'Anker 20W USB-C Charger',
                ProductField::Sku               => 'ANK-20W-01',
                ProductField::Barcode           => '6009880123456',
                ProductField::Category          => 'Chargers',
                ProductField::Brand             => 'Anker',
                ProductField::Description       => 'Fast charger with USB-C output',
                ProductField::MeasurementUnit   => 'pcs',
                ProductField::CostPrice         => '7500',
                ProductField::Price             => '11000',
                ProductField::Supplier          => 'Lagos Electronics Ltd',
                ProductField::ReorderPoint      => '10',
                ProductField::PreferredQuantity => '50',
                ProductField::LowStockThreshold => '5',
                ProductField::IsService         => 'No',
                ProductField::Status            => 'Yes',
                ProductField::ShowOnline        => 'Yes',
                ProductField::ShowInPos         => 'Yes',
                // Left blank on purpose: filling it in would suggest it imports.
                ProductField::Quantity          => '',
            })->all());
        });

        return $path;
    }

    /** @return array<int, string> */
    private function rowFor(Product $product): array
    {
        return collect(ProductField::exportColumns())
            ->map(fn (ProductField $field) => $this->cell($product, $field))
            ->all();
    }

    private function cell(Product $product, ProductField $field): string
    {
        return match ($field) {
            ProductField::Category => (string) ($product->category?->name ?? ''),
            ProductField::Supplier => (string) ($product->supplier?->name ?? ''),
            // Written as words rather than 1/0 so the file reads plainly in
            // Excel, and read back by RowParser which accepts both.
            ProductField::IsService  => $this->yesNo($product->is_service),
            ProductField::ShowOnline => $this->yesNo($product->show_online),
            ProductField::ShowInPos  => $this->yesNo($product->show_in_pos),
            ProductField::Status     => (string) $product->status,
            // Blank, not "0", when there is no cost. Zero is a claim about the
            // margin; blank is the truth that nobody has entered one.
            ProductField::CostPrice  => $product->cost_price === null ? '' : (string) $product->cost_price,
            ProductField::Quantity   => (string) $product->stock_quantity,
            default                  => (string) ($product->{$field->value} ?? ''),
        };
    }

    private function yesNo(mixed $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    private function query(Vendor $vendor, array $filters): Builder
    {
        return Product::query()
            ->with(['category:id,name', 'supplier:id,name'])
            ->where('vendor_id', $vendor->id)
            ->when(filled($filters['category_id'] ?? null), fn ($q) => $q->where('category_id', $filters['category_id']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(
                ($filters['low_stock_only'] ?? false) === true,
                fn ($q) => $q->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) < low_stock_threshold'),
            )
            ->orderBy('id');
    }

    /** @param  callable(callable(array<int, string>): void): void  $emit */
    private function write(string $path, string $format, callable $emit): void
    {
        $writer = $format === 'xlsx' ? new XlsxWriter() : new CsvWriter();
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues(
                collect(ProductField::exportColumns())->map(fn (ProductField $f) => $f->label())->all(),
            ));

            $emit(function (array $values) use ($writer) {
                $writer->addRow(Row::fromValues($values));
            });
        } finally {
            $writer->close();
        }
    }

    private function temporaryPath(?Vendor $vendor, string $format, ?string $stem = null): string
    {
        $directory = storage_path('app/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $stem ??= 'products-'.str($vendor?->name ?? 'catalogue')->slug();

        return $directory.'/'.$stem.'-'.now()->format('Y-m-d-His').'.'.($format === 'xlsx' ? 'xlsx' : 'csv');
    }
}
