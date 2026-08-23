<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One store's receipt layout.
 *
 * Always resolvable: forVendor() returns an unsaved instance carrying the column
 * defaults when a store has never opened the settings page, so the printer never
 * has to cope with a missing row.
 */
class VendorReceiptSetting extends Model
{
    protected $guarded = [];

    /**
     * Defaults for a store that has never opened the settings page.
     *
     * Column defaults only apply on insert, so without these forVendor()'s
     * unsaved model reports null for every toggle — and a brand-new store would
     * print a receipt with no number, no date and no cashier on it. That is the
     * most common path of all, so it must be the well-arranged one.
     */
    protected $attributes = [
        'show_logo'            => false,
        'header_alignment'     => 'center',
        'show_receipt_number'  => true,
        'show_cashier'         => true,
        'show_customer'        => true,
        'show_datetime'        => true,
        'show_item_unit_price' => true,
        'footer_alignment'     => 'center',
        'feed_lines'           => 2,
        'show_qr'              => true,
        'loyalty_enabled'      => false,
        'loyalty_goal'         => 10,
    ];

    protected function casts(): array
    {
        return [
            'show_logo'            => 'boolean',
            'show_receipt_number'  => 'boolean',
            'show_cashier'         => 'boolean',
            'show_customer'        => 'boolean',
            'show_datetime'        => 'boolean',
            'show_item_unit_price' => 'boolean',
            'feed_lines'           => 'integer',
            'show_qr'              => 'boolean',
            'loyalty_enabled'      => 'boolean',
            'loyalty_goal'         => 'integer',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public static function forVendor(Vendor $vendor): self
    {
        return static::firstOrNew(['vendor_id' => $vendor->id]);
    }

    /** The name printed at the top — the override when set, otherwise the store's. */
    public function displayName(Vendor $vendor): string
    {
        return trim((string) $this->header_name) !== ''
            ? (string) $this->header_name
            : $vendor->name;
    }

    /** Header lines under the name, blank ones dropped. */
    public function headerLines(): array
    {
        return array_values(array_filter([
            $this->header_tagline,
            $this->header_address,
            $this->header_phone,
            $this->header_extra,
        ], fn ($line) => trim((string) $line) !== ''));
    }
}
