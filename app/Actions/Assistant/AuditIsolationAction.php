<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantScope;

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
     * Whether the attempting user can query the given store — the same rule
     * the queries enforce (AssistantScope): within a business, the stores
     * ActiveStore gives them; without one, the stores they own.
     */
    public function canQueryStore(User $attempting, int $storeId, ?Vendor $vendor = null): bool
    {
        return AssistantScope::for($attempting, $vendor)->allows($storeId);
    }
}
