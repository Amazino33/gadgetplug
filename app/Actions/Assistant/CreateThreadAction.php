<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateThreadAction
{
    public function execute(User $user): AssistantThread
    {
        $ownedStores = Store::query()
            ->whereHas('vendor', fn ($v) => $v->where('user_id', $user->id))
            ->get();

        return DB::transaction(function () use ($user, $ownedStores) {
            $thread = AssistantThread::create([
                'user_id' => $user->id,
                'title' => 'New conversation',
                'store_id' => null,
                'last_message_at' => now(),
            ]);

            if ($ownedStores->count() === 1) {
                $store = $ownedStores->first();
                $thread->store_id = $store->id;
                $thread->save();

                AssistantMessage::create([
                    'thread_id' => $thread->id,
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                    'store_name_snapshot' => $store->name,
                    'role' => 'system',
                    'content' => "[Store context: {$store->name}]",
                    'is_prompt' => true,
                ]);
            } elseif ($ownedStores->count() === 0) {
                AssistantMessage::create([
                    'thread_id' => $thread->id,
                    'user_id' => $user->id,
                    'store_id' => null,
                    'role' => 'system',
                    'content' => 'No stores assigned.',
                    'is_prompt' => true,
                ]);
            }

            return $thread;
        });
    }
}
