{{--
    The shop's private verification photo, served only through the admin-only
    route. Tap to enlarge. Inline styles: the admin panel has no compiled
    theme of its own, so utility classes written here would not exist.
--}}
@php
    $vendor = $getRecord();
    $src = filled($vendor->verification_photo)
        ? route('free-shop.verification-photo', ['vendor' => $vendor->id])
        : null;
@endphp

@if ($src)
    <div x-data="{ open: false }">
        <button type="button" x-on:click="open = true" style="display:block;width:100%;padding:0;border:0;background:none;cursor:zoom-in;">
            <img src="{{ $src }}" alt="Verification photo of {{ $vendor->name }}"
                 style="width:100%;max-height:260px;object-fit:cover;border-radius:10px;">
        </button>
        <p style="font-size:12px;opacity:.6;margin-top:6px;">Tap to enlarge · <a href="{{ $src }}" target="_blank" rel="noopener" style="text-decoration:underline;">open full size</a></p>

        <template x-teleport="body">
            <div x-show="open" x-cloak x-on:click="open = false" x-on:keydown.escape.window="open = false"
                 style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.88);display:flex;align-items:center;justify-content:center;padding:16px;cursor:zoom-out;">
                <img src="{{ $src }}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;">
            </div>
        </template>
    </div>
@else
    <p style="font-size:14px;opacity:.6;">No photo — check the social media link.</p>
@endif
