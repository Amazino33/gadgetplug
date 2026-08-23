<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Services\Inventory\StoreStock;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Exception;

class AdjustStockAction
{
    /**
     * @throws Exception
     */
    public function execute(
        int $productId,
        int $quantityChanged,
        string $transactionType,
        ?int $userId = null,
        ?string $reference = null,
        ?string $description = null,
        ?int $auditSessionId = null,
        ?string $reasonCode = null,
        Store|int|null $store = null,
    ) {
        return DB::transaction(function () use ($productId, $quantityChanged, $transactionType, $userId, $reference, $description, $auditSessionId, $reasonCode, $store) {
            // 1. PESSIMISTIC LOCK: Lock the product row until the transaction is done.
            // If POS tries to sell this at the exact same millisecond, it will be forced to wait.
            // Still the product row and not merely the store row: it is what
            // serialises every writer of this product across all its stores, so
            // the mirror recompute below cannot read a half-written total.
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            // 2. The row this movement actually lands on.
            $row = StoreStock::lockedRow($product, $store);

            // 3. Prevent negative stock on sales — now against what this store
            // holds, not the vendor-wide total. Selling from a store that has
            // none must fail even when another store is full.
            if ($quantityChanged < 0 && $row->quantity < abs($quantityChanged)) {
                throw new Exception('Insufficient stock for product: ' . $product->name);
            }

            // 4. Move the stock. products.stock_quantity is not touched here —
            // ProductStoreStockObserver derives it from this row.
            $row->quantity += $quantityChanged;
            $row->save();

            // 5. Record the immutable movement in the ledger
            $ledger = InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
                'store_id'         => $row->store_id,
                'product_id'       => $product->id,
                'user_id'          => $userId,
                'transaction_type' => $transactionType,
                'quantity_change'  => $quantityChanged,
                'reference'        => $reference,
                'description'      => $description,
                'audit_session_id' => $auditSessionId,
                'reason_code'      => $reasonCode,
            ]);

            // A cashier is deliberately allowed to sell into stock an online
            // order has reserved — the goods are physically there, and a rider
            // who never shows up shouldn't leave them dead on the shelf. But
            // that's a decision only the storekeeper can see is happening if
            // told, so a sale that pushes this store's row into deficit
            // (reserved now exceeds what's physically left) flags whichever
            // online order(s) were counting on it. Scoped to 'pos_sale' only —
            // every other transaction type (restock, audit_correction, refund)
            // moves stock for reasons that have nothing to do with this.
            if ($transactionType === 'pos_sale' && $row->reserved > $row->quantity) {
                $this->notifyOfOversoldReservation($product, $row);
            }

            return $ledger;
        });
    }

    private function notifyOfOversoldReservation(Product $product, ProductStoreStock $row): void
    {
        $atRiskOrders = Order::whereIn('status', ['pending', 'confirmed', 'paid'])
            ->whereHas('items', fn ($q) => $q
                ->where('product_id', $product->id)
                ->whereHas('storeAllocations', fn ($sa) => $sa->where('store_id', $row->store_id)))
            ->with('items.vendor.users', 'items.vendor.user')
            ->get();

        if ($atRiskOrders->isEmpty()) {
            return;
        }

        $shortBy = $row->reserved - $row->quantity;

        foreach ($atRiskOrders as $order) {
            $vendor = $order->items->first(fn ($item) => $item->product_id === $product->id)?->vendor;

            if (! $vendor) {
                continue;
            }

            $recipients = $vendor->users()->get()
                ->push($vendor->user)
                ->filter()
                ->unique('id');

            foreach ($recipients as $user) {
                Notification::make()
                    ->title('POS sale oversold a reservation')
                    ->body("{$product->name} was sold at the till, but order #{$order->reference} was counting on {$shortBy} of that same stock. Check whether that order can still be fulfilled.")
                    ->icon('heroicon-o-exclamation-triangle')
                    ->danger()
                    ->sendToDatabase($user);
            }
        }
    }
}
