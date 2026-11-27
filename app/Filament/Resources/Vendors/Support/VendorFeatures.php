<?php

namespace App\Filament\Resources\Vendors\Support;

use App\Models\Vendor;
use Filament\Forms\Components\Toggle;

/**
 * The per-shop switches the platform controls, in one place: the list's
 * badges, the Features window, the bulk switch and the edit form all read
 * this, so they cannot drift apart.
 *
 * Settings a shop sets for itself (VAT, selling in halves) are shown as
 * badges but not switched from here — the shop's own settings page applies
 * their rules (halves cannot be turned off while stock holds a half).
 */
final class VendorFeatures
{
    /** Switched by the platform: column => [label, what it does]. */
    public const SWITCHES = [
        'online_sales_enabled' => [
            'Online sales',
            'Products show on the storefront and online orders come in; Orders appears in the shop\'s panel. Off: products disappear from the storefront and new online orders are blocked. Sales Point is unaffected; existing orders are untouched.',
        ],
        'stock_take_enabled' => [
            'Stock Take',
            'Counting aisle by aisle while the shop sells: Inventory → Stock Takes, Aisles, and the Stock Take phone app. Off: those are hidden and refused. Anything already recorded is kept.',
        ],
        'inventory_count_enabled' => [
            'Old Inventory Count',
            'The blind-count Inventory Count page, its in-progress card, and Start Inventory Count on products. It corrects stock against live stock, undoing sales made after counting; Stock Take replaces it. Off for new shops. Past counts stay under Audit Sessions.',
        ],
        'owner_can_manage_roles' => [
            'Owner manages roles',
            'The owner can create roles and choose what each can do, on the shop\'s Roles screen.',
        ],
    ];

    /** Set by the shop itself; shown, not switched here. */
    private const SHOP_SETTINGS = [
        'pos_vat_enabled'       => 'VAT',
        'allow_half_quantities' => 'Sells halves',
    ];

    public static function label(string $column): string
    {
        return self::SWITCHES[$column][0] ?? $column;
    }

    /** @return array<string, string> column => label */
    public static function options(): array
    {
        return array_map(fn (array $feature) => $feature[0], self::SWITCHES);
    }

    /**
     * The badges for a shop: every switch that is on, then its own settings.
     *
     * @return array<int, string>
     */
    public static function badges(Vendor $vendor): array
    {
        $on = [];

        foreach (self::SWITCHES as $column => [$label]) {
            if ($vendor->{$column}) {
                $on[] = $label;
            }
        }

        foreach (self::SHOP_SETTINGS as $column => $label) {
            if ($vendor->{$column}) {
                $on[] = $label;
            }
        }

        return $on;
    }

    /** One toggle per switch, each saying what it does. */
    public static function toggles(): array
    {
        return collect(self::SWITCHES)
            ->map(fn (array $feature, string $column) => Toggle::make($column)
                ->label($feature[0])
                ->helperText($feature[1]))
            ->values()
            ->all();
    }
}
