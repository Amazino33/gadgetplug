{{--
    The tip pop-up, at the end of every vendor-panel (and admin-panel) page.

    Skipped outright when no tip is live, so the common case costs one indexed
    query and no extra request. Otherwise the component loads lazily after the
    page, so choosing a tip never slows the page down.
--}}
@php
    $gpTipPreviewId = request()->integer('gp_tip_preview') ?: null;
    $gpTipsLive = auth()->check() && ($gpTipPreviewId || \App\Models\Tip::query()->live()->exists());
@endphp

@if ($gpTipsLive)
    @include('engagement.tip-scripts')
    @livewire(\App\Livewire\Engagement\TipPopup::class, [
        'routeName' => request()->route()?->getName(),
        'previewTipId' => $gpTipPreviewId,
    ])
@endif
