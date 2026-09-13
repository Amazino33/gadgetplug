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
| `app/Filament/Vendor/Resources/ImportLogs/ImportLogResource.php` | Import history: what ran, where it landed, who ran it |

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
- **`import_logs`.** Who, when, which file, which branch, counts, and the per-row errors. Surfaced to the vendor at Products → Import History.
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

## Onboarding a vendor from our end

Most vendors arrive with a catalogue already typed up somewhere else and send us
the spreadsheet rather than working the wizard themselves. Platform staff run
those imports, from the vendor panel, with that vendor selected in the tenant
switcher — there is no second wizard on the admin panel, because a second copy
of a five-step state machine is a second copy to keep correct.

**Access.** `ImportProducts::canAccess()` carries an explicit `isSuperAdmin()`
branch. It has to: Spatie's team scope pins `model_has_roles.team_id` to the
vendor, the global `super_admin` role is stored with a null team, and a
team-scoped permission check therefore excludes it silently. Without that branch
an admin can reach every other screen in a vendor's panel and not the one
onboarding actually runs through.

**What gets recorded.** `import_logs.performed_by_admin` is stamped when the run
starts, from `ImportProducts::actingAsAdmin()` — super admin **and** not an owner
or member of that vendor. The membership half matters: staff who also sell on the
marketplace are the vendor when they are in their own panel, and labelling their
own import "support" would name the wrong party. `products:import` on the CLI is
always stamped true, since reaching artisan means server access no vendor has.

**What the vendor sees.** Products → Import History, gated on the same
`import_products` permission (which owners always pass). The run is attributed to
"GadgetPlug support" rather than to an individual on our side — the vendor has no
way to evaluate a name they have never heard and every reason to hold the
platform to it. The individual stays on `user_id`.

**What the admin sees.** An amber banner on the import screen naming the vendor
they are about to import into. The tenant switcher is a small control in the
topbar, and importing six hundred products into the wrong vendor's catalogue
looks exactly like importing them into the right one until the vendor calls.

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

**Import — as platform staff**
- [ ] Sign in as a super admin, switch the tenant to a vendor you are no part of
- [ ] Products → Import Products is in the sidebar and opens
- [ ] The amber banner names that vendor, and the store banner names the branch
- [ ] Run a small import; the results line says "run by GadgetPlug support"
- [ ] Products → Import History shows the run, the file, the branch and "GadgetPlug support"
- [ ] Sign in as that vendor's owner: the same row is there, same attribution
- [ ] The owner's own import on the next row is attributed to their own name
- [ ] Another vendor's owner sees none of it
