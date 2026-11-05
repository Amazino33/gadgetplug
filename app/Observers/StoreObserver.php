<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Store;

class StoreObserver
{
    /**
     * Handle the Store "deleting" event.
     */
    public function deleting(Store $store): void
    {
        AssistantThread::where('store_id', $store->id)->update([
            'store_removed_at' => now(),
            'store_removed_name' => $store->name,
            'store_id' => null,
        ]);

        AssistantMessage::where('store_id', $store->id)
            ->whereNull('store_name_snapshot')
            ->update([
                'store_name_snapshot' => $store->name,
            ]);
    }
}
