<?php

namespace App\Services\Affiliate;

use App\Actions\Inventory\ReserveStockAction;
use App\Models\Affiliate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// An affiliate spending their own wallet balance to buy at a resale discount.
// Wallet-only, no top-up path: the purchase is rejected outright if the
// available balance can't cover it (v1 scope, per the locked decision).
// Discount applies to the subtotal only; this storefront charges no VAT or
// shipping fee on any online order today, so there is nothing else to add on
// top. Deliberately never calls AttributionService::attributeOrder() or
// CommissionService::createForOrder() — that absence alone is the entire
// no-self-commission guard, confirmed as the only commission-creation path
// in the whole app during Phase 0 recon.
class ResellerCheckoutService
{
    public function __construct(
        private ResellerDiscountResolver $discountResolver,
        private ReserveStockAction $reserveStock,
        private WalletService $walletService,
    ) {}

    /**
     * @param array<int, array{product: Product, quantity: int}> $lines
     */
    public function purchase(Affiliate $affiliate, array $lines, string $shippingAddress, ?string $localGovernment = null): Order
    {
        if (empty($lines)) {
            throw new RuntimeException('Cannot place a reseller order with no items.');
        }

        return DB::transaction(function () use ($affiliate, $lines, $shippingAddress, $localGovernment) {
            // Row lock as a mutex against a second concurrent purchase by the
            // same affiliate racing the balance check below — same pattern
            // AffiliateTaskService::evaluateAuto() already uses, stronger than
            // the existing (unlocked) payout-batch debit.
            $lockedAffiliate = Affiliate::where('id', $affiliate->id)->lockForUpdate()->first();
            $lockedAffiliate->loadMissing('user');

            if (blank($lockedAffiliate->user->phone ?? null)) {
                throw new RuntimeException('The affiliate has no phone number on file — required for order fulfillment.');
            }

            $preparedLines = [];
            $total = 0.0;

            foreach ($lines as $line) {
                $product  = $line['product'];
                $quantity = $line['quantity'];

                if ($quantity < 1) {
                    throw new RuntimeException("Quantity must be at least 1 for {$product->name}.");
                }

                $discount             = $this->discountResolver->resolveForProduct($product);
                $discountedUnitPrice  = round((float) $product->price * (1 - $discount / 100), 2);
                $total               += $discountedUnitPrice * $quantity;

                $preparedLines[] = [
                    'product'                => $product,
                    'quantity'                => $quantity,
                    'discounted_unit_price'   => $discountedUnitPrice,
                ];
            }

            $total = round($total, 2);

            $availableBalance = $this->walletService->availableBalance($lockedAffiliate->id);

            if ($availableBalance < $total) {
                throw new RuntimeException('Insufficient wallet balance for this purchase.');
            }

            $order = Order::create([
                'user_id'          => $lockedAffiliate->user_id,
                'reference'        => 'GP-RS-' . strtoupper(Str::random(10)),
                'customer_name'    => $lockedAffiliate->user->name,
                'customer_email'   => $lockedAffiliate->user->email,
                'customer_phone'   => $lockedAffiliate->user->phone,
                'shipping_address' => $shippingAddress,
                'local_government' => $localGovernment,
                'total_amount'     => $total,
                'status'           => 'confirmed',
                'payment_method'   => 'wallet',
            ]);

            foreach ($preparedLines as $line) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $line['product']->id,
                    'vendor_id'  => $line['product']->vendor_id,
                    'quantity'   => $line['quantity'],
                    'unit_price' => $line['discounted_unit_price'],
                    'unit_cost'  => $line['product']->cost_price,
                ]);

                $this->reserveStock->execute(
                    productId: $line['product']->id,
                    quantity: $line['quantity'],
                    reference: $order->reference,
                    description: "Reserved for reseller order #{$order->reference} (affiliate #{$lockedAffiliate->id}).",
                );
            }

            WalletTransaction::create([
                'affiliate_id' => $lockedAffiliate->id,
                'order_id'     => $order->id,
                'type'         => 'debit',
                'amount'       => -$total,
                'description'  => "Reseller purchase — order #{$order->reference}.",
            ]);

            return $order->fresh('items');
        });
    }
}
