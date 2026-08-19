# Product import & export

Vendors onboard with hundreds of products already typed up in another system —
Aronium, Loyverse, or a spreadsheet. This lets them bring that catalogue across
without re-keying it, and take it back out to edit offline.

## What it does not do

**Stock quantities are never imported.** This is deliberate, not an omission.

`products.stock_quantity` is a projection maintained by `ProductStoreStockObserver`
from the per-store rows in `product_store_stock`. The only legitimate way to move
stock is `AdjustStockAction`, which locks the product row, writes the per-store
row, and records an immutable `inventory_ledgers` entry against a **specific
store**. A spreadsheet cannot say which branch its numbers belong to.

Writing `stock_quantity` directly would be overwritten by the observer on the
next save, and would leave a stock movement no ledger accounts for. So the
`Quantity` column is exported for the vendor to read and ignored on the way back
in. Opening stock is set by a count or a procurement, which leave a record.

**Tax is not modelled.** The application has no tax concept anywhere (0
references in `app/`). Aronium's `Tax` and `IsTaxInclusivePrice` columns are
dropped. This is the one place the round trip is lossy, and it is a known
trade-off rather than a bug — adding tax columns nothing reads would imply a
pricing behaviour that does not exist.

## Round-trip guarantee

Every other field survives export → edit → re-import unchanged. This holds
because `App\Support\Import\ProductField` is the single definition driving all
four surfaces: the mapping dropdown, the automatic column guess, the export
header row, and the blank template. A field added there appears in all four.
Adding one to only the exporter is how a round trip quietly starts losing data.

## The pieces

| File | Job |
|---|---|
| `app/Support/Import/ProductField.php` | The field registry: labels, hints, types, aliases |
| `app/Services/Import/ColumnMapper.php` | Guesses field-per-column from normalised header text |
| `app/Services/Import/SpreadsheetReader.php` | Streams CSV/XLSX; caps at 20,000 rows |
| `app/Services/Import/RowParser.php` | Casts cells; refuses ambiguous values |
| `app/Services/Import/ImportPreparer.php` | Validates, finds duplicates, resolves matches — writes nothing |
| `app/Services/Import/ProductImporter.php` | Commits in one transaction, chunked |
| `app/Services/Export/ProductExporter.php` | Catalogue export, pre-import snapshot, blank template |
| `app/Filament/Vendor/Pages/ImportProducts.php` | The four-step wizard |

## Matching

SKU first, then barcode, both scoped to the vendor. A SKU is chosen by the
business; a barcode is whatever the manufacturer printed, and two different
products can legitimately carry the same one.

`unique(vendor_id, sku)` and `unique(vendor_id, barcode)` back this up. The
migration adding them **pre-checks and aborts with an actionable message** rather
than failing with a bare `1062`. Run `php artisan products:check-duplicates`
first — it is read-only and names every colliding group.

## Safety

- **Nothing is written before the final confirm.** Preparing and committing are
  separate operations. A vendor sees "X new, Y updated, Z skipped" first.
- **One transaction.** A file that fails on row 400 leaves the catalogue exactly
  as it was.
- **Pre-import snapshot.** Before any run that updates existing products, the
  current catalogue is exported to `storage/app/import-snapshots/`. This is what
  a database rollback cannot give once the transaction commits.
- **`import_logs`.** Who, when, which file, counts, and the per-row errors.
- **Permissions.** `import_products` and `export_products`, separate from
  `edit_products` — one import can rewrite every product a vendor has.

## Alias matching

Headers are normalised before comparison: camelCase is split, then everything
non-alphanumeric is stripped and lowercased. `Reorder Point`, `reorder_point` and
`ReorderPoint` all collapse to `reorderpoint`.

Two passes: exact alias matches are claimed first, partial matches second. This
is why a file containing both `Price` and `IsTaxInclusivePrice` gives `price` to
the right one. A field already claimed cannot be claimed again.

**Aronium's `LowStockWarning` is a boolean flag; `WarningQuantity` is the
number.** Only the number maps to `low_stock_threshold`. Listing the flag as an
alias — which an early version did — let it win the exact pass, after which
`"True"` failed integer validation and *every row in the file was skipped*.
There is a regression test for this.

## Manual smoke checklist

Run against a real vendor after deploying.

**Export**
- [ ] Products → Export → CSV downloads and opens in Excel with readable headers
- [ ] Export → Excel (.xlsx) opens without a repair prompt
- [ ] Filters work: one category only; drafts only; low-stock only
- [ ] A product with no cost price shows a **blank** Cost cell, not `0`
- [ ] Another vendor's products are absent

**Template**
- [ ] Import → Download blank template gives headers plus one example row
- [ ] The `Quantity` cell in the example is blank

**Import — happy path**
- [ ] Upload a real Aronium export; columns are guessed correctly
- [ ] `LowStockWarning` is left unmapped; `WarningQuantity` maps to Low Stock Threshold
- [ ] Preview shows sensible new/updated/skipped counts
- [ ] Nothing has changed in Products at this point
- [ ] Confirm; counts on the results screen match the preview
- [ ] Spot-check a product: unit, supplier, reorder point, category all landed
- [ ] **Its stock is 0**, even though the file had a quantity

**Import — re-import**
- [ ] Export, change two prices in Excel, re-import
- [ ] Reports "0 new, N updated"; no duplicates created
- [ ] The two prices changed; nothing else did
- [ ] "Download the pre-import copy" returns the old prices

**Import — bad data**
- [ ] A file with a blank name row, a negative price and a repeated SKU
- [ ] All three appear in the problems list with line numbers
- [ ] Importing anyway brings in only the good rows
- [ ] Upload a PDF — rejected at the door with a readable message

**Mapping templates**
- [ ] Save a mapping as "Aronium export"
- [ ] Upload the same file again; apply the template; columns fill in
- [ ] Saving the same name twice replaces rather than duplicates

**Volume**
- [ ] A 500+ row file completes without a timeout
- [ ] Check `import_logs` has one row with correct counts

**Permissions**
- [ ] A storekeeper sees neither Import nor Export
- [ ] Granting `import_products` reveals Import only
