<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\PickingItem;
use App\Services\Pickings\PickingLedger;
use Carbon\CarbonInterface;

class PickerItemsOutQuery extends BaseAssistantQuery
{
    /** Same gate as the Pickers screen. */
    public function requiredPermission(): ?string
    {
        return 'manage_pickings';
    }

    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));

        $hasAnyPickingActivity = PickingItem::query()
            ->whereHas('picking', fn ($q) => $q->where('store_id', $storeId))
            ->exists();

        if (! $hasAnyPickingActivity) {
            return QueryResult::empty('no_pickers');
        }

        $pickingItems = PickingItem::query()
            ->with('product')
            ->whereHas('picking', fn ($q) => $q->where('store_id', $storeId))
            ->get();

        $heldByProduct = [];

        foreach ($pickingItems as $item) {
            $held = (int) PickingLedger::heldQuantity($item);
            if ($held > 0) {
                $pId = (int) $item->product_id;
                $name = (string) ($item->product?->name ?? 'Unknown Product');

                if (! isset($heldByProduct[$pId])) {
                    $heldByProduct[$pId] = [
                        'product_id' => $pId,
                        'name' => $name,
                        'quantity' => 0,
                    ];
                }
                $heldByProduct[$pId]['quantity'] += $held;
            }
        }

        $items = array_values($heldByProduct);
        $totalHeld = array_sum(array_column($items, 'quantity'));

        return QueryResult::withData([
            'items' => $items,
            'total_held_units' => (int) $totalHeld,
        ]);
    }
}
