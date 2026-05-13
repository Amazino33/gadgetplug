<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 shadow-sm">
        <form wire:submit="save">
            {{ $this->form }}

            <div class="px-6 pb-6 pt-2 border-t border-gray-100 dark:border-white/10">
                <x-filament::button type="submit" size="lg" icon="heroicon-m-check">
                    Save Profile
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
