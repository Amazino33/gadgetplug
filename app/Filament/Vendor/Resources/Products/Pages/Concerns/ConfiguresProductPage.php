<?php

namespace App\Filament\Vendor\Resources\Products\Pages\Concerns;

use App\Models\Product;
use Filament\Support\Enums\Width;

/**
 * What the create and edit pages share.
 *
 * Width and the sticky actions are properties of one long form, which both
 * pages show. Default-unit handling is the same on both: the form offers a
 * choice, and once the unit rows exist the page points the product at one.
 */
trait ConfiguresProductPage
{
    // This form is two real columns of content, not a narrow single-column
    // one. At the panel default it was rendering at roughly half the space
    // available, which wrapped the selects onto two lines and pushed the
    // page well past one screen. Full width is what makes the pairings in
    // ProductForm actually sit side by side.
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    // The form runs close to three screens tall, so unpinned Save buttons mean
    // scrolling to the bottom every time — including after a correction made at
    // the top. Overridden per page rather than set on the panel: this is a
    // property of a long form, not of every form in the panel.
    public function areFormActionsSticky(): bool
    {
        return true;
    }

    /**
     * Points the product at the unit chosen under "Default unit at POS".
     *
     * Runs after the save, because a unit added in the same save has no id
     * until then. The choice is 'base' or 'unit:{name}'. A choice that no
     * longer matches a unit - it was renamed, or deleted - falls back to the
     * base unit, and so does a product whose default was deleted out from under
     * it (the foreign key nulls it).
     */
    protected function syncDefaultUnit(): void
    {
        /** @var Product $product */
        $product = $this->getRecord()->fresh(['units']);

        $choice = $this->data['default_unit_choice'] ?? null;
        $base = $product->units->firstWhere('is_base', true);

        $unit = null;

        if (is_string($choice) && str_starts_with($choice, 'unit:')) {
            $unit = $product->units->first(
                fn ($u) => ! $u->is_base && $u->is_active && $u->name === substr($choice, 5)
            );
        } elseif ($choice === null) {
            // The form said nothing: keep what is there, if it still exists.
            $unit = $product->units->firstWhere('id', $product->default_unit_id);
        }

        $unit ??= $base;

        if ($unit && (int) $product->default_unit_id !== (int) $unit->id) {
            // Straight to the table: bookkeeping, not an edit anyone should see
            // in the activity log or have re-run the unit and barcode sync.
            Product::whereKey($product->id)->update(['default_unit_id' => $unit->id]);
        }
    }
}
