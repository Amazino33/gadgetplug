<?php

namespace App\Jobs;

use App\Models\DeliveryMessage;
use App\Services\Messaging\MessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

// Sends an automated WhatsApp/SMS message that a status change, reminder or
// summary has already recorded as a 'queued' delivery_messages row.
//
// Queued so a slow gateway never holds up the request that triggered it
// (checkout, a status change). Dispatched after commit so the job can never run
// before the order/row it refers to is visible. Manual sends from the order page
// do NOT come through here: the person pressing the button wants the result now.
//
// The row stays 'queued' between attempts and only becomes 'failed' once the
// last attempt has failed, so the order page never shows "failed" for a message
// that is about to be retried.
class SendDeliveryMessageJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'messaging';

    public int $tries = 3;

    public function __construct(public int $deliveryMessageId)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(MessagingService $messaging): void
    {
        $message = DeliveryMessage::find($this->deliveryMessageId);

        // Deleted since dispatch, or already delivered by an earlier attempt
        // whose acknowledgement we lost — either way, nothing more to send.
        if (! $message || in_array($message->status, ['sent', 'link_generated'], true)) {
            return;
        }

        $result = $messaging->send($message);

        if ($result->status !== 'failed') {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $result->update(['status' => 'queued']);
        }

        throw new RuntimeException('Delivery message '.$message->id.' failed to send (attempt '.$this->attempts().').');
    }

    public function failed(Throwable $e): void
    {
        DeliveryMessage::whereKey($this->deliveryMessageId)
            ->whereNotIn('status', ['sent', 'link_generated'])
            ->update(['status' => 'failed']);

        Log::warning('Delivery message gave up after retries.', [
            'delivery_message_id' => $this->deliveryMessageId,
            'exception'           => $e->getMessage(),
        ]);
    }
}
