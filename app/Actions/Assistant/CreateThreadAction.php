<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantScope;
use Illuminate\Support\Facades\DB;

class CreateThreadAction
{
    /**
     * @param  Vendor|null  $vendor  The business the conversation belongs to (the
     *                               panel tenant). Null: stores the user owns, as before.
     */
    public function execute(User $user, ?Vendor $vendor = null): AssistantThread
    {
        $ownedStores = AssistantScope::for($user, $vendor)->stores();

        return DB::transaction(function () use ($user, $vendor, $ownedStores) {
            $thread = AssistantThread::create([
                'user_id' => $user->id,
                'vendor_id' => $vendor?->id ?? ($ownedStores->count() === 1 ? $ownedStores->first()->vendor_id : null),
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
