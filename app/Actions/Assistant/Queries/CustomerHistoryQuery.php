<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\PosCustomer;
use App\Models\PosSaleItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerHistoryQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];
        $customerName = trim((string) ($options['customer_name'] ?? ($options['name'] ?? '')));

        if ($customerName === '') {
            return QueryResult::empty('not_configured');
        }

        $user = auth()->user();
        $customerQuery = PosCustomer::query()
            ->where('name', 'LIKE', '%'.$customerName.'%');

        if ($user && ! $user->isSuperAdmin()) {
            $customerQuery->whereHas('vendor', fn ($v) => $v->where('user_id', $user->id));
        }

        $customer = $customerQuery->first();

        if (! $customer) {
            return QueryResult::withData([
                'customer_name' => $customerName,
                'purchases' => [],
            ]);
        }

        $items = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->leftJoin('stores', 'stores.id', '=', 'pos_sales.store_id')
            ->leftJoin('products', 'products.id', '=', 'pos_sale_items.product_id')
            ->where('pos_sales.customer_id', $customer->id)
            ->where('pos_sales.vendor_id', $customer->vendor_id)
            ->where('pos_sales.status', '!=', 'voided')
            ->orderByDesc(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'))
            ->orderByDesc('pos_sale_items.id')
            ->limit(5)
            ->select([
                'stores.name as store_name',
                'pos_sale_items.product_name',
                'pos_sale_items.quantity',
                'pos_sale_items.total',
                DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at) as purchase_date'),
            ])
            ->get();

        $purchases = $items->map(function ($row) {
            $date = $row->purchase_date ? Carbon::parse($row->purchase_date)->format('Y-m-d') : null;
            $qty = (float) $row->quantity;

            return [
                'store_name' => (string) ($row->store_name ?? 'Unknown Store'),
                'date' => $date,
                'product_name' => (string) $row->product_name,
                'quantity' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
                'total_amount' => (int) round(((float) $row->total) * 100),
            ];
        })->all();

        return QueryResult::withData([
            'customer_name' => $customer->name,
            'purchases' => $purchases,
        ]);
    }
}
