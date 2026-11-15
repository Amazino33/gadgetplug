@php
    // Rendered inside a ListProducts table toolbar (TOOLBAR_SEARCH_AFTER), so
    // the current Livewire component is that page. Both the admin and staff
    // All Products pages expose QUICK_FILTERS and toggleQuickFilter(); this
    // duck-types on them rather than naming either class, so the one view
    // serves both panels.
    $page = \Livewire\Livewire::current();
    $quickFilters = is_object($page) && defined($page::class.'::QUICK_FILTERS')
        ? constant($page::class.'::QUICK_FILTERS')
        : [];
@endphp

@if ($quickFilters && method_exists($page, 'toggleQuickFilter'))
    <div class="gp-quick-filters" role="group" aria-label="Quick filters">
        @foreach ($quickFilters as $filter => $label)
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
