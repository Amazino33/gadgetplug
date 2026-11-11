{{--
    Feature Adoption: which businesses use which features, and (one feature
    at a time) the businesses not using it yet, with the owner to call.
--}}
<x-filament-panels::page>
    <div class="gp-report">
        <p class="gp-report-note">
            @if ($this->featureKey)
                Businesses that haven’t used <strong>{{ $this->featureTitle }}</strong> yet, newest first, with the owner’s number to call.
                <a href="{{ \App\Filament\Pages\FeatureAdoptionPage::getUrl() }}" class="gp-report-link">Back to all features</a>
            @else
                A business counts as using a feature once anyone on its team has used it.
                “People” counts staff and owners who used it themselves, where the app records who did it; features marked “Whole business” don’t record that.
            @endif
            Figures are live, cached for up to {{ intdiv(\App\Support\Engagement\FeatureAdoption::CACHE_SECONDS, 60) }} minutes; “Refresh now” recounts.
        </p>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
