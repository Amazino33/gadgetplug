<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantThread;
use App\Models\Store;
use App\Models\User;

class AuditIsolationAction
{
    /**
     * Determines whether the attempting user can read the given thread.
     */
    public function canRead(AssistantThread $thread, User $attempting): bool
    {
        return (int) $thread->user_id === (int) $attempting->id;
    }

    /**
     * Determines whether the attempting user can mutate (rename, delete, switch store) the given thread.
     */
    public function canMutate(AssistantThread $thread, User $attempting): bool
    {
        return (int) $thread->user_id === (int) $attempting->id;
    }

    /**
     * Determines whether the attempting user can query the given store (vendor-owner relationship).
     */
    public function canQueryStore(User $attempting, int $storeId): bool
    {
        return Store::query()
            ->where('id', $storeId)
            ->whereHas('vendor', fn ($v) => $v->where('user_id', $attempting->id))
            ->exists();
    }
}
