@php
    use App\Filament\Resources\Products\Pages\ListProducts;

    // Rendered inside ListProducts' table toolbar (TOOLBAR_SEARCH_AFTER, scoped
    // to that page in AdminPanelProvider), so this is that component.
    $page = \Livewire\Livewire::current();
@endphp

@if ($page instanceof ListProducts)
    <div class="gp-quick-filters" role="group" aria-label="Quick filters">
        @foreach (ListProducts::QUICK_FILTERS as $filter => $label)
            @php($isActive = (bool) ($page->tableFilters[$filter]['isActive'] ?? false))

            <button
                type="button"
                wire:click="toggleQuickFilter('{{ $filter }}')"
                wire:loading.attr="disabled"
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                @class(['gp-quick-filter', 'gp-quick-filter-active' => $isActive])
            >
                @if ($isActive)
                    <x-filament::icon icon="heroicon-m-check" class="gp-quick-filter-icon" />
                @endif
                {{ $label }}
            </button>
        @endforeach
    </div>
@endif
