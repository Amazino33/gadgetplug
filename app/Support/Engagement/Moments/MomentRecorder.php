<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

use App\Models\PosSale;
use App\Models\Product;
use App\Models\Tip;
use App\Support\Pos\BusinessDate;
use Throwable;

/**
 * Turns saves into pending moments. Scoped to one request (or one queued job),
 * and built for the bulk case: a 500-row import or a 500-sale offline sync
 * does its real work once, not 500 times.
 *
 * The listeners in EngagementServiceProvider call the *Saved methods during
 * the save, so the decision sees the rows the surrounding transaction has
 * written so far, and get back a callback to run after commit (or null when
 * there is nothing to do). Recording happens only once the data is real.
 */
class MomentRecorder
{
    /** @var array<string, bool> moment key => some live tip waits for it */
    private array $wanted = [];

    /** @var array<int, true> vendors whose first-product question is settled */
    private array $productsSettled = [];

    /** @var array<string, true> vendor:cashier pairs already counted at the end of this request */
    private array $salesQueued = [];

    public function __construct(
        private readonly PendingMoments $pending,
        private readonly CashSalesTodayReached $cashSales,
        private readonly FirstProductAdded $firstProduct,
    ) {}

    /**
     * Cash sale saved. Sales dated another day are ignored before any query.
     * The count runs once per cashier per request, when the request ends:
     * the sync endpoint commits each sale in its own transaction, so "after
     * commit" would still mean once per sale. By the end of the request every
     * sale is committed (or rolled back), so one count sees the whole sync.
     */
    public function saleSaved(PosSale $sale): ?callable
    {
        if ($sale->payment_method !== 'cash' || $sale->status !== 'completed' || ! $sale->cashier_id) {
            return null;
        }

        $today = BusinessDate::today();

        if (BusinessDate::of($sale->completed_at ?? $sale->created_at) !== $today) {
            return null;
        }

        $pair = $sale->vendor_id.':'.$sale->cashier_id;

        if (isset($this->salesQueued[$pair]) || ! $this->isWanted(CashSalesTodayReached::KEY)) {
            return null;
        }

        $this->salesQueued[$pair] = true;
        $vendorId = (int) $sale->vendor_id;
        $cashierId = (int) $sale->cashier_id;

        app()->terminating(function () use ($vendorId, $cashierId, $today): void {
            try {
                $count = PosSale::query()
                    ->where('vendor_id', $vendorId)
                    ->where('cashier_id', $cashierId)
                    ->where('payment_method', 'cash')
                    ->where('status', 'completed')
                    ->whereBetween('completed_at', BusinessDate::boundsFor($today))
                    ->count();

                $this->pending->record($cashierId, $vendorId, CashSalesTodayReached::KEY, $count, $this->cashSales->expiresAt());
            } catch (Throwable $e) {
                report($e);
            }
        });

        return null;
    }

    /**
     * Product created. Asked once per vendor per request, at save time, so
     * the first row of an import sees itself as the only product and the
     * other 499 rows cost nothing.
     */
    public function productCreated(Product $product): ?callable
    {
        $vendorId = (int) $product->vendor_id;
        $userId = auth()->id();

        if (! $vendorId || ! $userId || isset($this->productsSettled[$vendorId])) {
            return null;
        }

        $this->productsSettled[$vendorId] = true;

        $isFirst = ! Product::query()
            ->where('vendor_id', $vendorId)
            ->whereKeyNot($product->getKey())
            ->exists();

        if (! $isFirst || ! $this->isWanted(FirstProductAdded::KEY)) {
            return null;
        }

        return fn () => $this->pending->record((int) $userId, $vendorId, FirstProductAdded::KEY, 1, $this->firstProduct->expiresAt());
    }

    /** No live tip waits for this moment: then there is nothing to record. */
    private function isWanted(string $momentKey): bool
    {
        return $this->wanted[$momentKey] ??= Tip::query()->live()->where('moment', $momentKey)->exists();
    }
}
