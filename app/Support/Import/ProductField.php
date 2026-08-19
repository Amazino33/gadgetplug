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
    case Sku               = 'sku';
    case Barcode           = 'barcode';
    case Category          = 'category';
    case Brand             = 'brand';
    case Description       = 'description';
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
     * Stock on hand. Exported so a vendor can read it offline, never imported.
     *
     * products.stock_quantity is a projection of the per-store rows maintained
     * by ProductStoreStockObserver, and the only legitimate way to move stock is
     * AdjustStockAction, which writes an immutable ledger entry against a
     * specific store. A spreadsheet cannot say which branch it means, so an
     * import that set this column would desync the mirror and leave a stock
     * movement no ledger accounts for.
     */
    case Quantity = 'quantity';

    /** The header this field is written under, and its first alias on the way back in. */
    public function label(): string
    {
        return match ($this) {
            self::Name              => 'Name',
            self::Sku               => 'SKU',
            self::Barcode           => 'Barcode',
            self::Category          => 'Category',
            self::Brand             => 'Brand',
            self::Description       => 'Description',
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
        };
    }

    /** Shown under the mapping dropdown, so the vendor knows what they are choosing. */
    public function hint(): ?string
    {
        return match ($this) {
            self::Name              => 'Required. The product name.',
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
            self::Quantity          => 'Exported for your reference only, never imported. Stock is changed by counts and procurement so every movement is recorded.',
            default                 => null,
        };
    }

    /**
     * Whether an import may write this field. The mapping screen offers only
     * these, so Quantity cannot be chosen by accident.
     */
    public function isImportable(): bool
    {
        return $this !== self::Quantity;
    }

    public function type(): FieldType
    {
        return match ($this) {
            self::CostPrice, self::Price => FieldType::Decimal,
            self::ReorderPoint, self::PreferredQuantity, self::LowStockThreshold, self::Quantity => FieldType::Integer,
            self::IsService, self::ShowOnline, self::ShowInPos => FieldType::Boolean,
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
            self::MeasurementUnit => ['unit', 'measurementunit', 'measurement unit', 'uom', 'unit of measure', 'measure'],
            self::CostPrice       => ['cost', 'cost price', 'costprice', 'buy price', 'buying price', 'purchase price', 'unit cost', 'wholesale'],
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
        };
    }

    /** Fields offered on the mapping screen, in the order they are shown. */
    public static function importable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f) => $f->isImportable()));
    }

    /**
     * Export column order. Quantity sits at the end, after everything that can
     * come back in, so the round-trippable block reads as one group.
     */
    public static function exportColumns(): array
    {
        return [...self::importable(), self::Quantity];
    }

    public static function required(): array
    {
        return [self::Name];
    }
}
