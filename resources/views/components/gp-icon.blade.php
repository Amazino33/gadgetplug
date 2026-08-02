@props(['name', 'class' => 'w-4 h-4'])

{{--
    Single inline-SVG icon set for the storefront.

    Named gp-icon rather than icon because blade-ui-kit/blade-icons (a Filament
    dependency) already registers <x-icon>, and it wins the name resolution —
    it would try to look every one of these up in its own icon sets and throw
    SvgNotFound.

    Replaces the emoji that used to stand in for icons across the category
    sidebar, trending cards and trust badges. Emoji render differently on every
    platform, are announced by screen readers as their full unicode name
    ("fire", "package"), and can't inherit colour or stroke weight — so they
    were never really icons, just characters that happened to look like some.

    Every icon is decorative here: the surrounding element carries the label, so
    these are hidden from assistive tech rather than announced twice.
--}}

@php
    $paths = [
        // Navigation and actions
        'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'cart'        => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'arrow-right' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'bolt'        => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',

        // Product categories
        'phone'       => '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="10" y1="18" x2="14" y2="18"/>',
        'laptop'      => '<rect x="3" y="4" width="18" height="12" rx="2"/><line x1="2" y1="20" x2="22" y2="20"/>',
        'headphones'  => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
        'watch'       => '<circle cx="12" cy="12" r="6"/><polyline points="12 10 12 12 13 13"/><path d="M9 2h6l.5 4M9 22h6l.5-4"/>',
        'gamepad'     => '<line x1="6" y1="11" x2="10" y2="11"/><line x1="8" y1="9" x2="8" y2="13"/><line x1="15" y1="12" x2="15.01" y2="12"/><line x1="18" y1="10" x2="18.01" y2="10"/><rect x="2" y="6" width="20" height="12" rx="4"/>',
        'camera'      => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'tablet'      => '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
        'cable'       => '<path d="M4 9h4V5a2 2 0 0 1 4 0v4h4"/><path d="M6 9v5a6 6 0 0 0 12 0V9"/><line x1="12" y1="20" x2="12" y2="22"/>',
        'package'     => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',

        // Social
        'x'           => '<path d="M18.9 2H22l-7.1 8.1L23 22h-6.6l-5.1-6.7L5.4 22H2.3l7.6-8.7L1.7 2h6.8l4.6 6.1zm-1.1 18h1.7L7.3 3.7H5.5z"/>',
        'facebook'    => '<path d="M15 3h-3a5 5 0 0 0-5 5v3H4v4h3v8h4v-8h3l1-4h-4V8a1 1 0 0 1 1-1h3z"/>',
        'linkedin'    => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
        'youtube'     => '<path d="M22.5 6.9a2.8 2.8 0 0 0-2-2C18.8 4.5 12 4.5 12 4.5s-6.8 0-8.5.4a2.8 2.8 0 0 0-2 2A29 29 0 0 0 1.1 12a29 29 0 0 0 .4 5.1 2.8 2.8 0 0 0 2 2c1.7.4 8.5.4 8.5.4s6.8 0 8.5-.4a2.8 2.8 0 0 0 2-2 29 29 0 0 0 .4-5.1 29 29 0 0 0-.4-5.1z"/><polygon points="9.8 15.3 15.5 12 9.8 8.7"/>',
    ];

    $path = $paths[$name] ?? $paths['package'];
    // Brand marks and the lightning bolt read as solid shapes; everything else
    // is a stroked outline so it can inherit weight from its container.
    $filled = in_array($name, ['x', 'facebook', 'linkedin', 'youtube', 'bolt', 'shield'], true);
@endphp

{{-- Class is composed once and merged, rather than emitting a second class
     attribute alongside $attributes, which browsers silently ignore. --}}
<svg
    {{ $attributes->merge(['class' => trim($class.' '.($filled ? 'fill-current' : 'fill-none stroke-current'))]) }}
    viewBox="0 0 24 24"
    @if (! $filled) stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" @endif
    aria-hidden="true"
    focusable="false"
>{!! $path !!}</svg>
