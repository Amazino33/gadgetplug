<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use Illuminate\Support\Facades\DB;

/**
 * Fewer messages to the same person: non-urgent messages (P2, P3) queued for
 * one number within the digest window are sent as one.
 *
 * Non-urgent jobs are dispatched with a delay of the window, so by the time
 * the first runs, anything else for that number in the window is already
 * queued. The earliest queued message leads: it takes the others' text into
 * its own, and they are marked merged (kept, for each order's history).
 *
 * A message that is not the leader waits a little and looks again: by then
 * it has been merged, or the leader has gone and it leads its own group.
 */
class MessageDigest
{
    /**
     * @return string 'lead' (send this message — it may now carry others),
     *                'merged' (already part of another), or 'wait' (not the leader yet)
     */
    public function prepare(DeliveryMessage $message): string
    {
        if ($message->isUrgent()) {
            return 'lead';
        }

        $window = (int) config('messaging.throttle.digest_window', 120);

        return DB::transaction(function () use ($message, $window) {
            $fresh = DeliveryMessage::whereKey($message->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status === DeliveryMessage::STATUS_MERGED) {
                return 'merged';
            }

            $earlier = $this->siblings($fresh)
                ->where('created_at', '>=', $fresh->created_at->copy()->subSeconds($window))
                ->where('id', '<', $fresh->id)
                ->exists();

            if ($earlier) {
                return 'wait';
            }

            $members = $this->siblings($fresh)
                ->where('id', '>', $fresh->id)
                ->where('created_at', '<=', $fresh->created_at->copy()->addSeconds($window))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($members->isEmpty()) {
                return 'lead';
            }

            $parts = collect([$fresh])->concat($members);

            $body = 'You have '.$parts->count()." updates from GadgetPlug:\n\n"
                .$parts->pluck('body')->implode("\n\n────────\n\n");

            $fresh->update([
                'body'              => $body,
                'provider_response' => array_merge((array) $fresh->provider_response, ['digest_of' => $parts->pluck('id')->all()]),
            ]);

            DeliveryMessage::whereIn('id', $members->pluck('id'))->update([
                'status'         => DeliveryMessage::STATUS_MERGED,
                'merged_into_id' => $fresh->id,
            ]);

            $message->setRawAttributes($fresh->getAttributes(), true);

            return 'lead';
        });
    }

    private function siblings(DeliveryMessage $message)
    {
        return DeliveryMessage::query()
            ->where('to_number', $message->to_number)
            ->where('channel', $message->channel)
            ->where('status', 'queued')
            ->where('priority', '>=', DeliveryMessage::P2)
            ->where('id', '!=', $message->id);
    }
}
