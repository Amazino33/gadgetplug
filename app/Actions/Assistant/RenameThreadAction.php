<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantThread;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class RenameThreadAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(AssistantThread $thread, string $title): void
    {
        $userId = auth()->id();
        if ($userId !== null && (int) $thread->user_id !== (int) $userId) {
            throw new AuthorizationException('You do not own this assistant thread.');
        }

        $trimmed = trim($title);

        if ($trimmed === '') {
            $firstUserMessage = $thread->messages()
                ->where('role', 'user')
                ->oldest('id')
                ->value('content');

            $trimmed = $firstUserMessage ? Str::limit(trim((string) $firstUserMessage), 60, '') : 'New conversation';
        }

        $cleanTitle = Str::limit($trimmed, 80, '');

        $thread->update([
            'title' => $cleanTitle,
        ]);
    }
}
