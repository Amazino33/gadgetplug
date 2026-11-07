<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 shadow-sm p-6">
        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6 pt-4 border-t border-gray-100 dark:border-white/10">
                <x-filament::button type="submit" size="lg" icon="heroicon-m-check">
                    Save Settings
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>

