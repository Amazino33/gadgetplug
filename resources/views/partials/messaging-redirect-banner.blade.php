@php
    $redirectTarget = \App\Services\Messaging\MessagingService::redirectTarget();
@endphp

@if ($redirectTarget)
    @php
        $maskedTarget = str_repeat('•', max(strlen($redirectTarget) - 4, 0)) . substr($redirectTarget, -4);
    @endphp

    {{-- Styled by the admin theme (resources/css/filament/admin/theme.css).
         On a phone the full warning pushed the page a third of a screen down
         on every visit, so below md it is one line that opens on a tap. --}}
    <div role="alert" data-testid="messaging-redirect-banner" class="gp-redirect-banner">
        <details class="gp-redirect-banner-phone">
            <summary aria-label="Test mode: messages go to •••{{ substr($redirectTarget, -4) }}. Show details">
                <span><strong>Test mode:</strong> messages go to <span class="gp-mono">•••{{ substr($redirectTarget, -4) }}</span></span>
                <span class="gp-redirect-banner-more" aria-hidden="true"></span>
            </summary>

            <p>
                No customer or storekeeper is receiving anything. Remove
                <code>MESSAGING_REDIRECT_ALL_TO</code> from the server's .env to go live.
            </p>
        </details>

        <div class="gp-redirect-banner-full">
            <strong>Messaging is in test mode.</strong>
            Every WhatsApp and SMS is being diverted to
            <span class="gp-mono">{{ $maskedTarget }}</span>
            and <strong>no customer or storekeeper is receiving anything</strong>.
            Remove <code>MESSAGING_REDIRECT_ALL_TO</code> from the server's .env to go live.
        </div>
    </div>
@endif
