{{--
    A tip's or a feature's picture, video or GIF: the poster first, the clip
    only once it's on screen (gpTipMedia in engagement/tip-scripts), and a play
    button instead of autoplay under Save-Data or reduced motion. The first
    play dispatches a bubbling `gp-media-played` event for the parent to log.

    @param array|null  $video     TipMedia::video(): {url, kind, poster}
    @param string|null $imageUrl  a still picture, used when there is no video
--}}
@if ($video)
    <div class="gp-tip-media" x-data="gpTipMedia(@js($video))">
        @if ($video['kind'] === 'mp4')
            <video
                x-ref="clip"
                class="gp-tip-clip"
                muted loop playsinline preload="none"
                @if ($video['poster']) poster="{{ $video['poster'] }}" @endif
            ></video>
        @else
            <img x-ref="clip" class="gp-tip-clip" alt="" src="{{ $video['poster'] ?? 'data:image/gif;base64,R0lGODlhAQABAAAAACw=' }}">
        @endif
        <button type="button" class="gp-tip-play" x-show="!playing" x-on:click="play()" aria-label="Play">
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z"/></svg>
        </button>
    </div>
@elseif ($imageUrl ?? null)
    <img class="gp-tip-image" src="{{ $imageUrl }}" alt="" loading="lazy">
@endif
