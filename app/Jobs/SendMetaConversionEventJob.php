<?php

namespace App\Jobs;

use App\Services\Meta\MetaConversionsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

// Delivers an already-built Meta CAPI payload (see MetaConversionsService::
// buildEventPayload, which does the hashing synchronously before this job is
// dispatched — only the network call itself needs to be async). Queued so a
// slow or unreachable Meta endpoint never blocks checkout; retries with
// backoff so a transient failure on the highest-value event (Purchase)
// doesn't just get dropped.
class SendMetaConversionEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private array $payload) {}

    public function payload(): array
    {
        return $this->payload;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(MetaConversionsService $service): void
    {
        $service->send($this->payload);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Meta CAPI event permanently failed after all retries.', [
            'exception' => $exception->getMessage(),
            'event_name' => $this->payload['data'][0]['event_name'] ?? null,
            'event_id'   => $this->payload['data'][0]['event_id'] ?? null,
        ]);
    }
}
