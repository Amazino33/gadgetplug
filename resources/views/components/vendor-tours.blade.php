{{--
    Guided tours for the vendor panel and the procurement wizard.

    Included from the vendor panel's BODY_END render hook and from the
    procurement wizard layout — the two places a vendor works — and nowhere
    else. The storefront and the admin panel never load it, which is why this
    has its own Vite entry instead of riding along in resources/js/app.js.

    The whole registry is inlined as JSON rather than fetched: it is a few
    kilobytes, it gzips to almost nothing, and one saved round trip matters more
    than the bytes on the connections these vendors are on.
--}}
@php
    $tourUser = auth()->user();

    // Inside a Filament panel the tenant is authoritative. The wizard runs on
    // its own layout with no panel, so fall back to the same resolution
    // ProcurementWizardController uses, and let the endpoint re-check it.
    $tourVendor = null;

    if ($tourUser) {
        try {
            $tourVendor = filament()->getTenant();
        } catch (\Throwable $e) {
            $tourVendor = null;
        }

        $tourVendor ??= $tourUser->ownedVendors()->first() ?? $tourUser->memberVendors()->first();
    }
@endphp

@if ($tourUser && $tourVendor)
    @php
        $tourPayload = [
            'endpoint'  => route('tours.progress'),
            'csrf'      => csrf_token(),
            'vendorId'  => $tourVendor->id,
            'autoOffer' => true,
            'seen'      => \App\Models\UserTourProgress::seenKeys((int) $tourUser->id, (int) $tourVendor->id),
            'tours'     => \App\Support\Tours\TourRegistry::forVendorSlug((string) $tourVendor->slug),
        ];
    @endphp

    <script type="application/json" id="gp-tours-config">@json($tourPayload)</script>

    @vite('resources/js/vendor-tours.js')
@endif
