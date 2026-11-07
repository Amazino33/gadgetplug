<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UnflagMessageAction
{
    public function execute(User $actor, AssistantMessage $message): AssistantMessage
    {
        // 1. Authorization check
        if (! $actor->isSuperAdmin() && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('Only administrators can unflag assistant messages.');
        }

        // 2. All-or-nothing: null all three flag fields
        $message->update([
            'flagged_at' => null,
            'flagged_by' => null,
            'flag_note' => null,
        ]);

        return $message->fresh();
    }
}
