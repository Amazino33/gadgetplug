@php
    $redirectTarget = \App\Services\Messaging\MessagingService::redirectTarget();
@endphp

@if ($redirectTarget)
    <div role="alert" data-testid="messaging-redirect-banner"
         class="mb-4 rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
        <strong>Messaging is in test mode.</strong>
        Every WhatsApp and SMS is being diverted to
        <span class="font-mono">{{ str_repeat('•', max(strlen($redirectTarget) - 4, 0)) . substr($redirectTarget, -4) }}</span>
        and <strong>no customer or storekeeper is receiving anything</strong>.
        Remove <code>MESSAGING_REDIRECT_ALL_TO</code> from the server's .env to go live.
    </div>
@endif
