<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PosSale;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Removes test trading data from one store so its figures stop lying.
 *
 * Reports first and refuses to touch anything without --force, because nothing
 * here is soft-deleted and none of it is recoverable afterwards.
 *
 * Two deliberate limits on the blast radius:
 *
 *  - Only THIS store's products, sales and stock. Other stores under the same
 *    vendor are untouched, which is the whole point of scoping to a store.
 *  - A shared online order loses only the lines belonging to this store. Orders
 *    can carry several vendors' items, so deleting a whole order because one
 *    line was ours would destroy someone else's sale. The order itself goes
 *    only if nothing is left in it.
 */
class PurgeStoreDataCommand extends Command
{
    protected $signature = 'store:purge
        {vendor : Vendor id or slug}
        {store : Store id or slug}
        {--pos-before= : Only delete POS sales completed on or before this date (Y-m-d)}
        {--keep-products : Leave the catalogue alone, remove trading data only}
        {--force : Actually delete. Without it this is a dry run}';

    protected $description = "Delete a store's products, POS sales, online order lines, reservations and matching financial entries";

    public function handle(): int
    {
        $vendor = $this->resolveVendor();
        $store  = $vendor ? $this->resolveStore($vendor) : null;

        if (! $vendor || ! $store) {
            return self::FAILURE;
        }

        $posBefore = $this->option('pos-before')
            ? Carbon::parse((string) $this->option('pos-before'))->endOfDay()
            : null;

        $this->newLine();
        $this->info("Vendor: {$vendor->name} (#{$vendor->id})");
        $this->info("Store:  {$store->name} (#{$store->id})");
        $this->line($posBefore
            ? 'POS sales: completed on or before ' . $posBefore->toDateString()
            : 'POS sales: ALL dates');
        $this->line($this->option('keep-products') ? 'Products: kept' : 'Products: deleted');
        $this->newLine();

        $scope = $this->gather($vendor, $store, $posBefore);

        $this->report($scope);

        if (array_sum(array_map('count', $scope)) === 0) {
            $this->info('Nothing matches - nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run. Nothing was deleted.');
            $this->line('Re-run with --force to apply. Take a database backup first - none of this can be undone.');

            return self::SUCCESS;
        }

        if (! $this->confirm('This permanently deletes the rows listed above. Continue?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->purge($scope, $store));

        $this->newLine();
        $this->info('Done. Re-check the dashboard - the figures should now reflect real trading only.');

        return self::SUCCESS;
    }

    /**
     * Collects every id up front so the report and the delete are guaranteed to
     * describe the same rows.
     *
     * @return array<string, array<int, int>>
     */
    private function gather(Vendor $vendor, Store $store, ?Carbon $posBefore): array
    {
        $productIds = $this->option('keep-products')
            ? []
            : DB::table('products')
                ->where('vendor_id', $vendor->id)
                ->where('store_id', $store->id)
                ->pluck('id')->all();

        $posSaleIds = DB::table('pos_sales')
            ->where('vendor_id', $vendor->id)
            ->where('store_id', $store->id)
            ->when($posBefore, fn ($q) => $q->where('completed_at', '<=', $posBefore))
            ->pluck('id')->all();

        // Online lines reach the store through their allocation rows; a line
        // with no allocation still counts if its product lives in this store.
        $orderItemIds = DB::table('order_items')
            ->where('order_items.vendor_id', $vendor->id)
            ->where(function ($q) use ($store, $productIds) {
                $q->whereIn('order_items.id', DB::table('order_item_store_allocations')
                    ->where('store_id', $store->id)->select('order_item_id'));

                if ($productIds !== []) {
                    $q->orWhereIn('order_items.product_id', $productIds);
                }
            })
            ->pluck('order_items.id')->all();

        $orderIds = $orderItemIds === [] ? [] : DB::table('order_items')
            ->whereIn('id', $orderItemIds)->distinct()->pluck('order_id')->all();

        // Asked of the model rather than hardcoded: FinancialLedger stores
        // whatever getMorphClass() returns, which with no morph map registered
        // is the fully qualified class name. A guessed string silently matched
        // nothing and reported zero ledger entries for sales that had them —
        // which would have deleted the sales and left their revenue on the books.
        $ledgerIds = $posSaleIds === [] ? [] : DB::table('financial_ledger_entries')
            ->where('source_type', (new PosSale())->getMorphClass())
            ->whereIn('source_id', $posSaleIds)
            ->pluck('id')->all();

        // A product still referenced by history we are KEEPING cannot go. The
        // 22 Aug sales being retained point at products in this store, and
        // deleting those would either fail on the foreign key or strip the line
        // items off a real sale. Such products are reported and skipped rather
        // than silently taking a kept sale down with them.
        $blocked = $productIds === [] ? [] : array_values(array_unique(array_merge(
            DB::table('pos_sale_items')
                ->whereIn('product_id', $productIds)
                ->when($posSaleIds !== [], fn ($q) => $q->whereNotIn('pos_sale_id', $posSaleIds))
                ->pluck('product_id')->all(),
            DB::table('order_items')
                ->whereIn('product_id', $productIds)
                ->when($orderItemIds !== [], fn ($q) => $q->whereNotIn('id', $orderItemIds))
                ->pluck('product_id')->all(),
            DB::table('procurement_items')
                ->whereIn('product_id', $productIds)
                ->pluck('product_id')->all(),
        )));

        return [
            'products'         => array_values(array_diff($productIds, $blocked)),
            'products_blocked' => $blocked,
            'pos_sales'        => $posSaleIds,
            'order_items'      => $orderItemIds,
            'orders_touched'   => $orderIds,
            'ledger_entries'   => $ledgerIds,
        ];
    }

    /** @param array<string, array<int, int>> $scope */
    private function report(array $scope): void
    {
        $ledgerValue = $scope['ledger_entries'] === [] ? 0.0 : (float) DB::table('financial_ledger_entries')
            ->whereIn('id', $scope['ledger_entries'])->sum('amount');

        $posValue = $scope['pos_sales'] === [] ? 0.0 : (float) DB::table('pos_sales')
            ->whereIn('id', $scope['pos_sales'])->sum('total');

        $this->table(['What', 'Rows', 'Value'], [
            ['Products',                 count($scope['products']),       '-'],
            ['Products kept (in use)',   count($scope['products_blocked']), 'referenced by history being kept'],
            ['POS sales',                count($scope['pos_sales']),      'NGN ' . number_format($posValue, 2)],
            ['Online order lines',       count($scope['order_items']),    '-'],
            ['Orders touched',           count($scope['orders_touched']), 'emptied ones are removed'],
            ['Financial ledger entries', count($scope['ledger_entries']), 'NGN ' . number_format($ledgerValue, 2)],
        ]);

        $this->line('Also cleared for this store: stock ledger entries, per-store stock rows and reservations held against them.');

        if ($scope['products_blocked'] !== []) {
            $this->newLine();
            $this->warn(count($scope['products_blocked']) . ' product(s) are still referenced by sales or procurement being kept, so they stay.');
            $this->line('Deleting them would break that retained history. Remove the referencing records first if they really must go.');
        }
    }

    /** @param array<string, array<int, int>> $scope */
    private function purge(array $scope, Store $store): void
    {
        // Children before parents throughout - nothing here relies on a
        // cascade, so the order is explicit and auditable.

        if ($scope['ledger_entries'] !== []) {
            DB::table('financial_ledger_entries')->whereIn('id', $scope['ledger_entries'])->delete();
        }

        if ($scope['pos_sales'] !== []) {
            DB::table('pos_sale_items')->whereIn('pos_sale_id', $scope['pos_sales'])->delete();
            DB::table('pos_sale_payments')->whereIn('pos_sale_id', $scope['pos_sales'])->delete();
            DB::table('pos_returns')->whereIn('original_sale_id', $scope['pos_sales'])->delete();
            DB::table('pos_sales')->whereIn('id', $scope['pos_sales'])->delete();
        }

        if ($scope['order_items'] !== []) {
            DB::table('order_item_store_allocations')->whereIn('order_item_id', $scope['order_items'])->delete();
            DB::table('order_items')->whereIn('id', $scope['order_items'])->delete();
        }

        // An order that has lost every line is now an empty shell; one that
        // still has another vendor's lines stays exactly as it was.
        foreach ($scope['orders_touched'] as $orderId) {
            if (DB::table('order_items')->where('order_id', $orderId)->doesntExist()) {
                DB::table('orders')->where('id', $orderId)->delete();
            }
        }

        // Store-scoped stock records. Reservations go with them - they only
        // ever pointed at the order lines just removed.
        DB::table('inventory_ledgers')->where('store_id', $store->id)->delete();

        if ($scope['products'] !== []) {
            DB::table('product_store_stock')->whereIn('product_id', $scope['products'])->delete();
            DB::table('wishlists')->whereIn('product_id', $scope['products'])->delete();
            DB::table('product_tag')->whereIn('product_id', $scope['products'])->delete();
            DB::table('products')->whereIn('id', $scope['products'])->delete();
        } else {
            // Catalogue kept: clear the reservation counters the deleted orders
            // were holding, rather than leaving stock permanently spoken for.
            DB::table('product_store_stock')->where('store_id', $store->id)->update(['reserved' => 0]);
        }
    }

    private function resolveVendor(): ?Vendor
    {
        $needle  = trim((string) $this->argument('vendor'));

        $matches = Vendor::query()
            ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle))
            ->orWhere('slug', $needle)
            ->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty()
                ? "No vendor matches \"{$needle}\"."
                : "\"{$needle}\" matches several vendors - use the id.");

            return null;
        }

        return $matches->first();
    }

    private function resolveStore(Vendor $vendor): ?Store
    {
        $needle = trim((string) $this->argument('store'));

        $matches = Store::where('vendor_id', $vendor->id)
            ->where(fn ($q) => $q
                ->when(ctype_digit($needle), fn ($qq) => $qq->orWhere('id', (int) $needle))
                ->orWhere('slug', $needle))
            ->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty()
                ? "No store matches \"{$needle}\" under {$vendor->name}."
                : "\"{$needle}\" matches several stores - use the id.");

            $this->line('Stores: ' . Store::where('vendor_id', $vendor->id)->get()
                ->map(fn ($s) => "#{$s->id} {$s->slug}")->implode(', '));

            return null;
        }

        return $matches->first();
    }
}
