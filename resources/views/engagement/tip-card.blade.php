{{--
    One tip card. Drawn by the pop-up (interactive) and by the admin preview
    (inert). $card comes from App\Support\Engagement\TipCard. The Alpine
    methods it calls (tap, notNow, dismiss, rate) live on window.gpTip, from
    engagement/tip-scripts.
--}}
@php($on = fn (string $js) => $interactive ? 'x-on:click="'.$js.'"' : '')
<div
    class="gp-tip-card gp-tip-card--{{ $card['style'] }}"
    role="dialog"
    aria-modal="{{ $card['style'] === 'modal' ? 'true' : 'false' }}"
    aria-labelledby="gp-tip-title"
    @if ($interactive)
        x-bind:style="dragY ? `transform: translateY(${dragY}px)` : ''"
    @endif
>
    @if ($card['style'] === 'sheet')
        <div
            class="gp-tip-handle-zone"
            @if ($interactive)
                x-on:touchstart.passive="dragStart($event)"
                x-on:touchmove.passive="dragMove($event)"
                x-on:touchend="dragEnd()"
            @endif
        >
            <span class="gp-tip-handle" aria-hidden="true"></span>
        </div>
    @endif

    <button type="button" class="gp-tip-close" aria-label="Close" {!! $on('notNow()') !!}>
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
    </button>

    @if ($card['isAnnouncement'])
        <span class="gp-tip-new">New</span>
    @endif

    @include('engagement.media', ['video' => $card['video'], 'imageUrl' => $card['imageUrl']])

    <h2 class="gp-tip-title" id="gp-tip-title">{{ $card['title'] }}</h2>

    @if ($card['messageHtml'] !== '')
        <div class="gp-tip-message">{!! $card['messageHtml'] !!}</div>
    @endif

    <div class="gp-tip-actions">
        @if ($card['hasButton'] && $card['buttonLabel'])
            <button
                type="button"
                class="gp-tip-primary"
                {!! $on('tap()') !!}
                @if ($interactive) x-bind:disabled="busy" @endif
            >{{ $card['buttonLabel'] }}</button>
        @elseif ($card['isAnnouncement'])
            <button type="button" class="gp-tip-primary" {!! $on('notNow()') !!}>Got it</button>
        @endif

        <div class="gp-tip-secondary">
            @if ($card['showNotNow'] && ! $card['isAnnouncement'])
                <button type="button" class="gp-tip-link" {!! $on('notNow()') !!}>Not now</button>
            @endif
            @unless ($card['isAnnouncement'])
                <button type="button" class="gp-tip-link" {!! $on('dismiss()') !!}>Don't show again</button>
            @endunless
        </div>
    </div>

    <div class="gp-tip-feedback" @if ($interactive) x-show="feedbackVisible" x-transition.opacity @endif>
        @if ($interactive)
            <span x-show="!answered">Was this helpful?</span>
            <span x-show="answered" x-cloak>Thanks for telling us.</span>
            <button type="button" class="gp-tip-thumb" x-bind:aria-pressed="answered === 'yes'" x-on:click="rate('yes')" aria-label="Yes, helpful">👍</button>
            <button type="button" class="gp-tip-thumb" x-bind:aria-pressed="answered === 'no'" x-on:click="rate('no')" aria-label="No, not helpful">👎</button>
        @else
            <span>Was this helpful?</span>
            <span class="gp-tip-thumb">👍</span><span class="gp-tip-thumb">👎</span>
        @endif
    </div>
</div>
