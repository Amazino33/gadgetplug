<?php

declare(strict_types=1);

namespace App\Support\Pos;

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorReceiptSetting;
use App\Services\Inventory\TillStore;

/**
 * Everything a till needs to know about its vendor without asking again.
 *
 * A sale rung with no signal has no server id, so there is no receipt document
 * to fetch — the browser has to build the 80mm document itself. Until this was
 * sent it knew the vendor's name and nothing else: no address, no branch, no
 * layout settings, no VAT rate it could name. So it printed its own on-screen
 * modal instead, and the customer got a visibly worse receipt purely because
 * the connection was down.
 *
 * Sent at login and again when a session is opened. Login alone was too rare:
 * a cashier stays signed in on a shared till for days, so a vendor who changed
 * their receipt layout would not see it on paper until somebody happened to log
 * out. Opening a session is the once-a-shift moment that costs nothing.
 */
class TillProfile
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $cashier, ?Vendor $vendor): array
    {
        return [
            'vat_enabled' => (bool) ($vendor->pos_vat_enabled ?? true),
            'vat_rate'    => (float) ($vendor->pos_vat_rate ?? 7.5),
            'vendor_name' => $vendor?->name,
            'receipt'     => self::receipt($vendor),
            'store'       => self::store($cashier, $vendor),
        ];
    }

    /**
     * The vendor's receipt layout, flattened for the browser.
     *
     * Deliberately the same field names the blade template reads, so the two
     * renderers can be compared line by line when either is changed.
     *
     * @return array<string, mixed>
     */
    private static function receipt(?Vendor $vendor): array
    {
        if (! $vendor) {
            return [];
        }

        $settings = VendorReceiptSetting::forVendor($vendor);

        return [
            'header_name'          => $settings->displayName($vendor),
            'header_alignment'     => $settings->header_alignment ?? 'center',
            'header_tagline'       => $settings->header_tagline,
            'header_address'       => $settings->header_address,
            'header_phone'         => $settings->header_phone,
            'header_extra'         => $settings->header_extra,
            'show_logo'            => (bool) $settings->show_logo,
            // Absolute, because the receipt is written into an iframe of its
            // own. Offline it resolves only if the service worker already
            // cached it, which is why the document drops a logo that fails.
            'logo_url'             => $vendor->logo ? asset('storage/'.$vendor->logo) : null,
            'show_receipt_number'  => (bool) $settings->show_receipt_number,
            'show_datetime'        => (bool) $settings->show_datetime,
            'show_cashier'         => (bool) $settings->show_cashier,
            'show_customer'        => (bool) $settings->show_customer,
            'show_item_unit_price' => (bool) $settings->show_item_unit_price,
            'footer_text'          => $settings->footer_text,
            'footer_alignment'     => $settings->footer_alignment ?? 'center',
            'feed_lines'           => (int) ($settings->feed_lines ?? 2),
            // No QR key on purpose. The code addresses a copy the customer can
            // open online, which does not exist until the sale has synced, so
            // an offline receipt never has one to print.
        ];
    }

    /**
     * Which branch this cashier is standing in, resolved exactly as a sale is.
     *
     * `show` mirrors the server template: a vendor with one store gains nothing
     * from repeating itself, so the branch line only appears when there is more
     * than one and the customer can act on knowing which.
     *
     * @return array<string, mixed>
     */
    private static function store(User $cashier, ?Vendor $vendor): array
    {
        if (! $vendor) {
            return ['show' => false];
        }

        $storeId = TillStore::resolve($cashier, (int) $vendor->id);
        $store   = $storeId ? Store::find($storeId) : null;

        return [
            'name'    => $store?->name,
            'address' => $store?->address,
            'phone'   => $store?->phone,
            'show'    => $store !== null
                && Store::where('vendor_id', $vendor->id)->count() > 1,
        ];
    }
}
