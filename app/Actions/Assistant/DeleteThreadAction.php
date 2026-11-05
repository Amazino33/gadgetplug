<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantThread;
use Illuminate\Auth\Access\AuthorizationException;

class DeleteThreadAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(AssistantThread $thread): void
    {
        $userId = auth()->id();
        if ($userId !== null && (int) $thread->user_id !== (int) $userId) {
            throw new AuthorizationException('You do not own this assistant thread.');
        }

        $thread->delete();
    }
}
