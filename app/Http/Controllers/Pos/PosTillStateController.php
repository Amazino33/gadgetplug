<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosTillSyncState;
use App\Models\Store;
use App\Exceptions\TillBranchUnclear;
use App\Services\Inventory\TillStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A till saying what it still holds unsynced, per branch, each time it tries
 * to sync. The server cannot see those sales until they arrive; this is how a
 * stock take's approval screen knows to warn that some are still out there.
 *
 * Every report replaces the device's last one: a branch it no longer lists is
 * a branch where it now holds nothing.
 */
class PosTillStateController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id'           => ['required', 'integer'],
            'device_id'           => ['required', 'string', 'max:64'],
            'pending'             => ['nullable', 'array', 'max:50'],
            'pending.*.store_id'  => ['nullable', 'integer'],
            'pending.*.count'     => ['required', 'integer', 'min:0'],
            'pending.*.oldest_at' => ['nullable', 'date'],
        ]);

        $vendorId = (int) $data['vendor_id'];
        $user     = $request->user();
        $stores   = Store::query()->where('vendor_id', $vendorId)->pluck('id')->map(fn ($id) => (int) $id);

        // A sale queued before the till recorded its branch names none: it
        // belongs to the till's own branch, if that can be told.
        $tillStore = null;
        try {
            $tillStore = TillStore::resolve($user, $vendorId);
        } catch (TillBranchUnclear) {
            // Several branches and none chosen: such sales cannot be placed.
        }

        $byStore = collect($data['pending'] ?? [])
            ->map(fn ($p) => [...$p, 'store_id' => $p['store_id'] ?? $tillStore])
            ->filter(fn ($p) => $p['store_id'] !== null && $stores->contains((int) $p['store_id']))
            ->groupBy('store_id')
            ->map(fn ($rows) => [
                'count'     => (int) $rows->sum('count'),
                'oldest_at' => $rows->pluck('oldest_at')->filter()->map(fn ($t) => Carbon::parse($t)->utc())->min(),
            ]);

        DB::transaction(function () use ($data, $vendorId, $user, $byStore, $tillStore) {
            PosTillSyncState::query()
                ->where('device_id', $data['device_id'])
                ->where('vendor_id', $vendorId)
                ->update(['pending_sales' => 0, 'oldest_pending_at' => null, 'reported_at' => now()]);

            // Nothing pending still says "this till is here and clear".
            $report = $byStore->isEmpty() && $tillStore ? collect([$tillStore => ['count' => 0, 'oldest_at' => null]]) : $byStore;

            foreach ($report as $storeId => $state) {
                PosTillSyncState::updateOrCreate(
                    ['device_id' => $data['device_id'], 'store_id' => (int) $storeId],
                    [
                        'vendor_id'         => $vendorId,
                        'user_id'           => $user->id,
                        'pending_sales'     => $state['count'],
                        'oldest_pending_at' => $state['oldest_at'],
                        'reported_at'       => now(),
                    ],
                );
            }
        });

        return response()->json(['status' => 'ok']);
    }
}
