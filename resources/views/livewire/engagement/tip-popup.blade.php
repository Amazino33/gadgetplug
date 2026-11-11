<div class="gp-tip-root">
    @if ($card)
        <div
            x-data="gpTip(@js(['delay' => $card['delay'], 'preview' => $preview, 'event' => $buttonEvent, 'answered' => $answered]))"
            x-init="schedule()"
            x-on:gp-media-played="$wire.videoPlayed()"
            x-on:keydown.escape.window="visible && notNow()"
        >
            <div
                class="gp-tip-layer gp-tip-layer--{{ $card['style'] }}"
                x-show="visible"
                x-transition.opacity.duration.200ms
                style="display: none"
                wire:ignore.self
            >
                <div class="gp-tip-backdrop" x-on:click="notNow()"></div>

                @if ($preview)
                    <div class="gp-tip-preview-badge">Preview: nothing is recorded</div>
                @endif

                @include('engagement.tip-card', ['card' => $card, 'interactive' => true])
            </div>
        </div>
    @endif
</div>
