@php
    use App\Filament\Resources\Products\Tables\ProductsTable;
    use App\Services\Catalogue\ProductMediaActivity;
    use Illuminate\Support\Number;

    // All Products on a phone: one card per product (ProductsTable's
    // mobile_card column, hidden from md up where the table takes over).
    // Shows what the desktop columns show for the editor — nothing more.

    /** @var \App\Models\Product $record */
    $record = $getRecord();
    $isLinked = $record->isLinked();

    // A resold listing shows its supplier's name and photos, as customers see it.
    $source = $isLinked ? $record->catalogueSource() : $record;
    $onlineName = $source?->online_name;
    $thumb = $record->catalogueImageUrl('thumb');
    $photoCount = $isLinked
        ? ($source?->getMedia(ProductMediaActivity::COLLECTION)->count() ?? 0)
        : (int) ($record->image_count ?? 0);
    $isOnline = ProductsTable::isVisibleOnline($record);
    $vendorLink = ProductsTable::vendorLinkLabel($record);

    // The row's own actions, prepared the way Filament's actions cell prepares
    // them — same objects, same authorize()/visible() checks — and drawn as
    // full-width buttons instead of links.
    $actions = collect($getTable()->getRecordActions())
        ->map(fn ($action) => $action->getClone()->record($record))
        ->reject(fn ($action) => $action->isHidden())
        ->map(fn ($action) => $action->button()->size('lg')->extraAttributes(['class' => 'gp-card-action'], merge: true));
@endphp

<div class="gp-card">
    <div class="gp-card-main">
        @if (filled($thumb))
            <img src="{{ $thumb }}" alt="" loading="lazy" class="gp-card-thumb" />
        @else
            <div class="gp-card-thumb gp-card-thumb-empty">
                <x-filament::icon icon="heroicon-o-camera" class="gp-card-thumb-icon" />
                <span>No photo</span>
            </div>
        @endif

        <div class="gp-card-body">
            <p class="gp-card-title">{{ filled($onlineName) ? $onlineName : $record->name }}</p>

            @if (filled($onlineName))
                <p class="gp-card-subtitle">Shelf name: {{ $record->name }}</p>
            @endif

            <p class="gp-card-meta">
                <span>{{ $record->vendor?->name }}</span>
                <span class="gp-card-price">{{ Number::currency((float) $record->price, 'NGN') }}</span>
            </p>

            <div class="gp-card-badges">
                <x-filament::badge :color="$photoCount === 0 ? 'danger' : 'gray'" size="sm">
                    {{ $photoCount === 0 ? 'No photo' : $photoCount . ' ' . str('photo')->plural($photoCount) }}
                </x-filament::badge>

                <x-filament::badge :color="$isOnline ? 'success' : 'gray'" size="sm">
                    {{ $isOnline ? 'Online' : 'Offline' }}
                </x-filament::badge>

                @if (! $isLinked && blank($onlineName))
                    <x-filament::badge color="warning" size="sm">No online name</x-filament::badge>
                @endif
            </div>
        </div>
    </div>

    @if ($isLinked)
        <div class="gp-card-linked">
            <p><strong>Linked to supplier</strong> · {{ $vendorLink }}</p>
            <p>Photos and online name are managed on the supplier's product.</p>
            <a href="{{ ProductsTable::supplierRowUrl($record) }}" class="gp-card-linked-link">Open supplier product</a>
        </div>
    @elseif (filled($vendorLink))
        <p class="gp-card-note">{{ $vendorLink }}</p>
    @endif

    @if ($actions->isNotEmpty())
        <div class="gp-card-actions">
            @foreach ($actions as $action)
                {{ $action }}
            @endforeach
        </div>
    @endif
</div>
