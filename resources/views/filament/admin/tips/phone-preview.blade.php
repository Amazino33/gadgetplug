{{--
    The tip card in a phone-sized frame. Inert: nothing here logs or navigates.
    window.gpTipMedia comes from the admin panel's render hook on the tip pages.
--}}
<div class="gp-tip-phone">
    <div class="gp-tip-phone-screen">
        <div class="gp-tip-phone-page" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
        <div class="gp-tip-phone-layer gp-tip-phone-layer--{{ $card['style'] }}">
            @include('engagement.tip-card', ['card' => $card, 'interactive' => false])
        </div>
    </div>
    <p class="gp-tip-phone-note">
        Appears {{ $card['delay'] }} second{{ $card['delay'] === 1 ? '' : 's' }} after the page loads
        · {{ $card['style'] === 'modal' ? 'centre modal' : 'bottom sheet' }}
    </p>
</div>
