<?php

namespace App\Jobs;

use App\Models\DeliveryMessage;
use App\Services\Messaging\MessageDigest;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\WhatsAppGate;
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
// WhatsApp through Wawp is throttled (WhatsAppGate): each lane has its own
// queue (P1 is drained first), and a message that may not go yet is released
// back to the queue with a delay — never sleep() in a worker. Non-urgent
// messages to the same number are combined first (MessageDigest).
//
// Retries: a release is not a failure, so attempts are not what limits this
// job — send_failures on the row is. Three failed sends and it gives up; the
// row stays 'queued' between them, so the order page never shows "failed" for
// a message that is about to be retried.
class SendDeliveryMessageJob implements ShouldQueue
{
    use Queueable;

    /** SMS and anything not throttled. */
    public const QUEUE = 'messaging';

    /** WhatsApp lanes, drained in this order by the worker. */
    public const LANE_QUEUES = [1 => 'messaging-p1', 2 => 'messaging-p2', 3 => 'messaging-p3'];

    public const MAX_SEND_FAILURES = 3;

    /** Thrown sends (provider errors) before the queue gives up; releases don't count. */
    public int $maxExceptions = self::MAX_SEND_FAILURES;

    public function __construct(public int $deliveryMessageId)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    public static function queueFor(DeliveryMessage $message): string
    {
        return $message->channel === 'whatsapp'
            ? (self::LANE_QUEUES[(int) $message->priority] ?? self::LANE_QUEUES[2])
            : self::QUEUE;
    }

    /** Time-bound rather than attempt-bound: a P3 may wait overnight for its window. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(3);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(MessagingService $messaging): void
    {
        $message = DeliveryMessage::find($this->deliveryMessageId);

        // Deleted since dispatch, already delivered by an earlier attempt whose
        // acknowledgement we lost, combined into another, or held while paused.
        if (! $message || in_array($message->status, ['sent', 'link_generated', DeliveryMessage::STATUS_MERGED, DeliveryMessage::STATUS_HELD, 'failed'], true)) {
            return;
        }

        if ($messaging->throttles($message)) {
            $digest = app(MessageDigest::class)->prepare($message);

            if ($digest === 'merged') {
                return;
            }

            if ($digest === 'wait') {
                $this->release(random_int(15, 30));

                return;
            }

            $decision = app(WhatsAppGate::class)->decide($message);

            switch ($decision['action']) {
                case 'wait':
                    $this->release($decision['seconds']);

                    return;

                case 'hold':
                    $message->update(['status' => DeliveryMessage::STATUS_HELD]);

                    return;

                case 'sms':
                    $this->settle($messaging->sendAsSms($message, $decision['reason'] ?? 'whatsapp_unavailable'));

                    return;
            }
        }

        $result = $messaging->send($message);

        // This very failure may have tripped the brake: an urgent message
        // still reaches the customer, by SMS.
        if ($result->status === 'failed' && $result->isUrgent() && $result->channel === 'whatsapp'
            && $messaging->throttles($result) && app(WhatsAppGate::class)->isPaused()) {
            $this->settle($messaging->sendAsSms($result, 'whatsapp_paused'));

            return;
        }

        $this->settle($result);
    }

    /** A failed send is retried (with backoff) until MAX_SEND_FAILURES, then given up. */
    private function settle(DeliveryMessage $result): void
    {
        if ($result->status !== 'failed') {
            return;
        }

        // Refused on purpose (a marketing message on Wawp): never retried.
        if (($result->provider_response['error'] ?? null) === 'marketing_refused') {
            return;
        }

        $failures = (int) $result->send_failures + 1;

        if ($failures < self::MAX_SEND_FAILURES) {
            $result->update(['status' => 'queued', 'send_failures' => $failures]);

            throw new RuntimeException('Delivery message '.$result->id.' failed to send (failure '.$failures.').');
        }

        $result->update(['send_failures' => $failures]);

        $this->fail(new RuntimeException('Delivery message '.$result->id.' gave up after '.$failures.' failed sends.'));
    }

    public function failed(Throwable $e): void
    {
        DeliveryMessage::whereKey($this->deliveryMessageId)
            ->whereNotIn('status', ['sent', 'link_generated', DeliveryMessage::STATUS_MERGED, DeliveryMessage::STATUS_HELD])
            ->update(['status' => 'failed']);

        Log::warning('Delivery message gave up after retries.', [
            'delivery_message_id' => $this->deliveryMessageId,
            'exception'           => $e->getMessage(),
        ]);
    }
}
