{{-- The outer element is unconditional on purpose: Livewire requires a single
     root tag in every render, and returning nothing when there is no choice to
     offer threw RootTagMissingFromViewException on every panel page a
     single-store vendor opened. The emptiness is expressed inside it instead.

     Nothing is shown when the user holds no store, or only one: a dropdown with
     a single unchangeable entry is noise in an already busy topbar. --}}
<div>
@if ($active && $hasChoice)
    <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
        <button type="button"
            x-on:click="open = !open"
            class="flex items-center gap-2 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 transition hover:bg-gray-50 dark:hover:bg-white/10">
            <x-heroicon-m-building-storefront class="h-4 w-4 text-primary-600 dark:text-primary-400"/>
            <span class="max-w-[10rem] truncate">{{ $active->name }}</span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-gray-400" x-bind:class="open && 'rotate-180'"/>
        </button>

        <div x-show="open" x-cloak x-transition
            x-on:click.outside="open = false"
            class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-lg">
            <p class="border-b border-gray-100 dark:border-white/10 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                Working in
            </p>

            <div class="max-h-72 overflow-y-auto py-1">
                @foreach ($stores as $store)
                    <button type="button"
                        wire:click="select({{ $store->id }})"
                        x-on:click="open = false"
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5
                            {{ $store->id === $active->id ? 'font-semibold text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-200' }}">
                        <span class="min-w-0 truncate">
                            {{ $store->name }}
                            @unless ($store->is_active)
                                <span class="ml-1 text-[10px] font-medium text-red-600 dark:text-red-400">(inactive)</span>
                            @endunless
                        </span>
                        @if ($store->id === $active->id)
                            <x-heroicon-m-check class="h-4 w-4 shrink-0"/>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>
@endif
</div>
