<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Every product field an import can fill and an export can emit.
 *
 * One definition drives four things that would otherwise drift apart: the import
 * mapping dropdown, the automatic column guess, the export header row, and the
 * blank template. A field added here appears in all four; a field added to only
 * one of them is how a round trip quietly starts losing data.
 *
 * Aliases are matched loosely - case, spaces, underscores and punctuation are
 * all stripped before comparison - because "Reorder Point", "reorder_point" and
 * "ReorderPoint" are the same column wearing three POS systems' conventions.
 * Aronium's headers are one alias set among several, never the assumed shape.
 */
enum ProductField: string
{
    case Name              = 'name';
    // Set by GadgetPlug for online search. Exported for everyone; written by an
    // import only for users holding update_product_online_name (ProductImporter).
    case OnlineName        = 'online_name';
    case Sku               = 'sku';
    case Barcode           = 'barcode';
    case Category          = 'category';
    case Brand             = 'brand';
    case Description       = 'description';
    case MetaDescription   = 'meta_description';
    case MeasurementUnit   = 'measurement_unit';
    case CostPrice         = 'cost_price';
    case Price             = 'price';
    case Supplier          = 'supplier';
    case ReorderPoint      = 'reorder_point';
    case PreferredQuantity = 'preferred_quantity';
    case LowStockThreshold = 'low_stock_threshold';
    case IsService         = 'is_service';
    case Status            = 'status';
    case ShowOnline        = 'show_online';
    case ShowInPos         = 'show_in_pos';

    /**
     * Stock on hand, in base units.
     *
     * On the way in it is an OPENING balance for new products only, booked
     * through AdjustStockAction as a ledgered "Opening stock — import" movement
     * into the product's home branch — never written to the stock column, which
     * is a projection of the per-store rows. An existing product's stock is
     * never touched by an import: a spreadsheet is a poor record of what is on
     * the shelf today, and counts and procurement are how that moves.
     */
    case Quantity = 'quantity';

    // ── Selling units ──
    // Everything below sits after the original eighteen columns, so a file
    // made from the old template reads exactly as it always did.

    case WholesalePrice = 'wholesale_price';
    case AltBarcode     = 'alt_barcode';

    case Unit2Name           = 'unit_2_name';
    case Unit2Contains       = 'unit_2_contains';
    case Unit2Price          = 'unit_2_price';
    case Unit2WholesalePrice = 'unit_2_wholesale_price';
    case Unit2Barcode        = 'unit_2_barcode';

    case Unit3Name           = 'unit_3_name';
    case Unit3Contains       = 'unit_3_contains';
    case Unit3Price          = 'unit_3_price';
    case Unit3WholesalePrice = 'unit_3_wholesale_price';
    case Unit3Barcode        = 'unit_3_barcode';

    case Unit4Name           = 'unit_4_name';
    case Unit4Contains       = 'unit_4_contains';
    case Unit4Price          = 'unit_4_price';
    case Unit4WholesalePrice = 'unit_4_wholesale_price';
    case Unit4Barcode        = 'unit_4_barcode';

    /** The extra unit numbers a row can carry. */
    public const UNIT_SLOTS = [2, 3, 4];

    /** The five fields of extra unit $n, keyed by part. */
    public static function unitFields(int $n): array
    {
        return [
            'name'      => self::from("unit_{$n}_name"),
            'contains'  => self::from("unit_{$n}_contains"),
            'price'     => self::from("unit_{$n}_price"),
            'wholesale' => self::from("unit_{$n}_wholesale_price"),
            'barcode'   => self::from("unit_{$n}_barcode"),
        ];
    }

    /** "Unit 3 Price" → 3; null for anything that is not an extra-unit field. */
    public function unitSlot(): ?int
    {
        return preg_match('/^unit_(\d)_/', $this->value, $m) ? (int) $m[1] : null;
    }

    /**
     * Codes rather than numbers: read as text, so 6009880123456 never becomes
     * 6.00988E+12 and a leading zero is never lost.
     */
    public function isIdentifier(): bool
    {
        return in_array($this, [self::Sku, self::Barcode, self::AltBarcode, self::Unit2Barcode, self::Unit3Barcode, self::Unit4Barcode], true);
    }

    /** Whether the value is written straight onto the products row. */
    public function isProductColumn(): bool
    {
        return $this->unitSlot() === null && ! in_array($this, [self::AltBarcode, self::Quantity], true);
    }

    /** The header this field is written under, and its first alias on the way back in. */
    public function label(): string
    {
        return match ($this) {
            self::Name              => 'Name',
            self::OnlineName        => 'Online Name',
            self::Sku               => 'SKU',
            self::Barcode           => 'Barcode',
            self::Category          => 'Category',
            self::Brand             => 'Brand',
            self::Description       => 'Description',
            self::MetaDescription   => 'Meta Description',
            self::MeasurementUnit   => 'Unit',
            self::CostPrice         => 'Cost',
            self::Price             => 'Price',
            self::Supplier          => 'Supplier',
            self::ReorderPoint      => 'Reorder Point',
            self::PreferredQuantity => 'Preferred Quantity',
            self::LowStockThreshold => 'Low Stock Threshold',
            self::IsService         => 'Is Service',
            self::Status            => 'Status',
            self::ShowOnline        => 'Show Online',
            self::ShowInPos         => 'Show In POS',
            self::Quantity          => 'Quantity',
            self::WholesalePrice    => 'Wholesale Price',
            self::AltBarcode        => 'Alt Barcode',
            default                 => $this->unitLabel(),
        };
    }

    /** "Unit 2 Name", "Unit 2 Contains", "Unit 2 Price", "Unit 2 Wholesale Price", "Unit 2 Barcode". */
    private function unitLabel(): string
    {
        $n = $this->unitSlot();

        return "Unit {$n} " . match (true) {
            str_ends_with($this->value, '_name')            => 'Name',
            str_ends_with($this->value, '_contains')        => 'Contains',
            str_ends_with($this->value, '_wholesale_price') => 'Wholesale Price',
            str_ends_with($this->value, '_price')           => 'Price',
            default                                         => 'Barcode',
        };
    }

    /** Shown under the mapping dropdown, so the vendor knows what they are choosing. */
    public function hint(): ?string
    {
        return match ($this) {
            self::Name              => 'Required. The product name.',
            self::OnlineName        => 'The name customers see online. Set by GadgetPlug; ignored unless you may edit online names.',
            self::MetaDescription   => 'The search-result description. Set by GadgetPlug; ignored unless you may edit online names.',
            self::Sku               => 'Used to match existing products first. Required unless a barcode is given.',
            self::Barcode           => 'Used to match existing products when there is no SKU.',
            self::Category          => 'Matched by name; a category that does not exist yet is created.',
            self::CostPrice         => 'What you pay. Leave blank rather than entering 0 when it is unknown.',
            self::Price             => 'What you sell for.',
            self::Supplier          => 'Matched by name against your suppliers, and created if new.',
            self::LowStockThreshold => 'The level at which the product is flagged as low.',
            self::ReorderPoint      => 'The level at which you intend to reorder.',
            self::Status            => 'Yes, true, 1 or enabled counts as published. Anything else is a draft.',
            self::IsService         => 'Services hold no stock and are excluded from counts.',
            self::Quantity          => 'Opening stock in base units, for NEW products only. Existing products keep their stock. May be negative.',
            self::WholesalePrice    => 'Wholesale price of one base unit. Leave blank for retail only.',
            self::AltBarcode        => 'A second barcode for the base unit.',
            self::Unit2Name, self::Unit3Name, self::Unit4Name => 'A bigger unit, e.g. Crate. Matched by name, so re-importing updates it.',
            self::Unit2Contains, self::Unit3Contains, self::Unit4Contains => 'How many base units it holds — a whole number, 2 or more.',
            self::Unit2Price, self::Unit3Price, self::Unit4Price => 'Its own price, set by you. 0 or blank saves it switched off.',
            default                 => null,
        };
    }

    /**
     * Whether an import may write this field. Every field can be mapped;
     * Quantity only ever sets opening stock on new products.
     */
    public function isImportable(): bool
    {
        return true;
    }

    public function type(): FieldType
    {
        return match ($this) {
            self::CostPrice, self::Price, self::WholesalePrice,
            self::Unit2Price, self::Unit3Price, self::Unit4Price,
            self::Unit2WholesalePrice, self::Unit3WholesalePrice, self::Unit4WholesalePrice => FieldType::Decimal,
            self::ReorderPoint, self::PreferredQuantity, self::LowStockThreshold => FieldType::Integer,
            // Decimal so a shop that sells halves can open with 19.5 crates;
            // ProductImporter refuses a fraction from every other shop.
            self::Quantity => FieldType::Decimal,
            self::IsService, self::ShowOnline, self::ShowInPos => FieldType::Boolean,
            // Contains is checked when the unit is built, so a bad value skips
            // that one unit with a warning instead of refusing the whole row.
            default => FieldType::Text,
        };
    }

    /**
     * Header spellings seen in the wild. Aronium's exact headers are in here, as
     * are Loyverse's and the shapes people type by hand.
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Name    => ['name', 'product', 'product name', 'item', 'item name', 'title'],
            self::Sku     => ['sku', 'code', 'item code', 'product code', 'reference', 'ref', 'article', 'plu'],
            self::Barcode => ['barcode', 'bar code', 'ean', 'upc', 'gtin', 'scan code'],
            // Aronium calls it ProductGroup; Loyverse and most spreadsheets say Category.
            self::Category => ['category', 'productgroup', 'product group', 'group', 'department', 'class'],
            self::Brand    => ['brand', 'make', 'manufacturer'],
            self::Description     => ['description', 'details', 'notes', 'long description'],
            self::OnlineName      => ['online name', 'onlinename', 'online title', 'web name', 'display name', 'seo name', 'seo title'],
            self::MetaDescription => ['meta description', 'metadescription', 'seo description', 'search description'],
            self::MeasurementUnit => ['unit', 'base unit', 'baseunit', 'base_unit', 'measurementunit', 'measurement unit', 'uom', 'unit of measure', 'measure'],
            // "Wholesale" used to land here, which wrote a selling price into
            // the cost column. It is Wholesale Price's now.
            self::CostPrice       => ['cost', 'cost price', 'costprice', 'buy price', 'buying price', 'purchase price', 'unit cost'],
            self::Price           => ['price', 'selling price', 'sellprice', 'sell price', 'retail', 'retail price', 'unit price', 'sale price'],
            self::Supplier        => ['supplier', 'distributor', 'wholesaler'],
            self::ReorderPoint    => ['reorderpoint', 'reorder point', 'reorder level', 'reorder', 'min stock', 'minimum stock', 'min qty'],
            self::PreferredQuantity => ['preferredquantity', 'preferred quantity', 'preferred qty', 'order quantity', 'max stock'],
            // Aronium splits this into a flag (LowStockWarning) and a number
            // (WarningQuantity). Only the number maps. Listing the flag here
            // let it claim this field in the exact pass, which then rejected
            // "True" as an invalid whole number and skipped every single row.
            self::LowStockThreshold => ['warningquantity', 'warning quantity', 'low stock', 'low stock threshold', 'lowstockthreshold', 'alert quantity'],
            self::IsService         => ['isservice', 'is service', 'service', 'service item', 'non stock', 'nonstock'],
            self::Status            => ['status', 'isenabled', 'is enabled', 'enabled', 'active', 'is active', 'published', 'visible'],
            self::ShowOnline        => ['show online', 'showonline', 'online', 'web', 'ecommerce', 'sell online'],
            self::ShowInPos         => ['show in pos', 'showinpos', 'pos', 'sell in pos', 'in pos'],
            self::Quantity          => ['quantity', 'qty', 'stock', 'stock quantity', 'on hand', 'onhand', 'in stock', 'balance'],
            self::WholesalePrice    => ['wholesale', 'wholesale price', 'wholesaleprice', 'trade price'],
            self::AltBarcode        => ['alt barcode', 'altbarcode', 'alternate barcode', 'second barcode', 'barcode 2'],
            // Each unit field answers only to its own label: "Unit 2 Price"
            // must never be read as the base price, nor the other way round.
            default                 => [],
        };
    }

    /** Fields offered on the mapping screen, in the order they are shown. */
    public static function importable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f) => $f->isImportable()));
    }

    /**
     * Export column order: the original eighteen (Quantity last among them,
     * exactly where it always was), then the selling-unit columns.
     */
    public static function exportColumns(): array
    {
        return self::cases();
    }

    public static function required(): array
    {
        return [self::Name];
    }
}
