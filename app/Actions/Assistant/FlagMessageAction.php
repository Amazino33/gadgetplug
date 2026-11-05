<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class FlagMessageAction
{
    public function execute(User $actor, AssistantMessage $message, ?string $note = null): AssistantMessage
    {
        // 1. Authorization check — only super admin or admin-panel gate
        if (! $actor->isSuperAdmin() && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('Only administrators can flag assistant messages.');
        }

        // 2. Validation
        if ($note !== null && mb_strlen(trim($note)) > 280) {
            throw ValidationException::withMessages([
                'flag_note' => 'Flag note must not exceed 280 characters.',
            ]);
        }

        $trimmedNote = $note !== null ? trim($note) : null;
        if ($trimmedNote === '') {
            $trimmedNote = null;
        }

        // 3. Last-write-wins: overwrite flag fields
        // Invariant: flagged_at and flagged_by are guaranteed non-null when flagged
        $message->update([
            'flagged_at' => now(),
            'flagged_by' => $actor->id,
            'flag_note' => $trimmedNote,
        ]);

        return $message->fresh();
    }
}
