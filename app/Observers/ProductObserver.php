<?php

namespace App\Observers;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Services\ActiveStore;
use App\Services\DefaultStore;
use Illuminate\Support\Facades\DB;
use LogicException;

// Gives a newly created product its opening stock row.
//
// Setting products.stock_quantity at creation time is how starting stock has
// always been expressed in this codebase — seeders, factories, fixtures, and
// any future import all do it. Once the per-store rows became the source of
// truth that convention quietly stopped meaning anything: the column said ten,
// the store row said nothing, and the first sale counted down from zero.
//
// So the column keeps its old meaning at exactly one moment — creation — and
// is translated here into the row that now owns it. Afterwards it is a pure
// mirror again, maintained by ProductStoreStockObserver, and writing to it
// directly does nothing.
class ProductObserver
{
    /**
     * Every product gets a home store before it is ever written.
     *
     * Home store decides which branch's inventory, count sheet and till a
     * product appears in, so a product without one is invisible everywhere —
     * not an edge case but a product nobody can sell. Only the panel form sets
     * it explicitly, and it is far from the only way a product gets created:
     * seeders, imports, factories and tests all call Product::create()
     * directly. Filling it here rather than in the form means the invariant
     * holds for all of them.
     *
     * The branch being worked in wins, but only if it belongs to this
     * product's own vendor — an active store from another tenant would
     * otherwise home the product in someone else's business.
     */
    public function creating(Product $product): void
    {
        if ($product->store_id !== null) {
            return;
        }

        $active = ActiveStore::currentId();

        $product->store_id = ($active !== null && Store::query()
            ->whereKey($active)
            ->where('vendor_id', $product->vendor_id)
            ->exists())
                ? $active
                : DefaultStore::seedFor($product->vendor)->id;
    }

    /**
     * Refuse to re-home a product while its stock is spoken for.
     *
     * Reservations live on the store row and are released or dispatched
     * against that same row. Moving the product mid-flight would carry those
     * units to another branch while the order that reserved them still points
     * at the first — the same class of split the Phase 6 default-store guard
     * exists to prevent, so it uses the same authority: reserved > 0.
     *
     * Thrown rather than silently skipped, and thrown from updating() so the
     * change never reaches the database. LogicException matches how Order,
     * Expense and ProductStoreStock already refuse edits to settled records.
     */
    public function updating(Product $product): void
    {
        if (! $product->isDirty('store_id')) {
            return;
        }

        $from = $product->getOriginal('store_id');

        if ($from === null) {
            return;
        }

        $reserved = (int) ProductStoreStock::query()
            ->where('product_id', $product->id)
            ->where('store_id', $from)
            ->value('reserved');

        if ($reserved > 0) {
            throw new LogicException(
                "Cannot change this product's store while {$reserved} unit(s) are reserved for orders at its current one. Dispatch or cancel those orders first."
            );
        }
    }

    /**
     * Carry the stock with the product.
     *
     * A product's home store decides which branch's inventory, count sheet and
     * till it appears in; leaving its units behind would show it in the new
     * branch at zero while the stock sat unreachable in the old one. Both
     * records of "which branch" move together or neither does.
     *
     * The old row is removed rather than zeroed, so a product keeps exactly one
     * stock row — the invariant stock:verify-mirror now asserts. Both writes go
     * through the model so ProductStoreStockObserver recomputes the mirror;
     * the total is unchanged by a move, so the mirror simply stays correct.
     */
    public function updated(Product $product): void
    {
        if (! $product->wasChanged('store_id')) {
            return;
        }

        $from = $product->getOriginal('store_id');
        $to = $product->store_id;

        if ($from === null || $to === null || $from === $to) {
            return;
        }

        DB::transaction(function () use ($product, $from, $to) {
            $source = ProductStoreStock::query()
                ->where('product_id', $product->id)
                ->where('store_id', $from)
                ->lockForUpdate()
                ->first();

            $quantity = (int) ($source->quantity ?? 0);
            $reserved = (int) ($source->reserved ?? 0);

            $destination = ProductStoreStock::firstOrNew([
                'product_id' => $product->id,
                'store_id'   => $to,
            ]);

            $destination->quantity = (int) ($destination->quantity ?? 0) + $quantity;
            $destination->reserved = (int) ($destination->reserved ?? 0) + $reserved;
            $destination->save();

            $source?->delete();

            // Nothing to record when an empty product moves: a ledger of zeroes
            // is noise in the one log people read to find where goods went.
            if ($quantity === 0) {
                return;
            }

            $names = Store::whereIn('id', [$from, $to])->pluck('name', 'id');
            $description = "Home store changed — {$quantity} unit(s) moved from "
                .($names[$from] ?? "store {$from}").' to '.($names[$to] ?? "store {$to}").'.';

            // Two entries, one per branch, so each branch's movement log tells
            // the truth on its own: units left here, units arrived there.
            foreach ([[$from, -$quantity], [$to, $quantity]] as [$storeId, $change]) {
                InventoryLedger::create([
                    'vendor_id'        => $product->vendor_id,
                    'store_id'         => $storeId,
                    'product_id'       => $product->id,
                    'user_id'          => auth()->id(),
                    'transaction_type' => 'store_transfer',
                    'quantity_change'  => $change,
                    'reference'        => "product-{$product->id}-home-store",
                    'description'      => $description,
                ]);
            }
        });
    }

    public function created(Product $product): void
    {
        $quantity = (int) ($product->stock_quantity ?? 0);
        $reserved = (int) ($product->reserved_stock ?? 0);

        // Idempotent by (product, store): the Phase 2a backfill and the 2b
        // re-sync both write this same row, and neither may collide with this.
        $existing = ProductStoreStock::query()
            ->where('product_id', $product->id)
            ->exists();

        if ($existing) {
            return;
        }

        // The opening stock belongs at the product's home store. Falling back
        // to the vendor default only when no home was named — otherwise a
        // product homed at one branch would have its stock created at another,
        // and identity and quantity would disagree from the first moment.
        //
        // seedFor rather than a bare lookup for that fallback: a vendor with no
        // default store would otherwise make product creation throw, and
        // refusing to create a product because of a missing store is a worse
        // failure than simply creating the store it should already have had.
        $storeId = $product->store_id ?? DefaultStore::seedFor($product->vendor)->id;

        ProductStoreStock::create([
            'product_id' => $product->id,
            'store_id'   => $storeId,
            'quantity'   => $quantity,
            'reserved'   => $reserved,
        ]);
    }
}
