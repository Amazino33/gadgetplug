<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantThread;
use App\Models\User;

class StartThreadAction
{
    public function execute(User $user, ?string $title = null): AssistantThread
    {
        return AssistantThread::create([
            'user_id' => $user->id,
            'title' => $title,
            'last_message_at' => now(),
        ]);
    }
}
