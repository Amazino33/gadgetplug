<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 shadow-sm">
        <form wire:submit="save">
            {{ $this->form }}

            <div class="px-6 pb-6 pt-2 border-t border-gray-100 dark:border-white/10 flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" size="lg" icon="heroicon-m-check">
                    Save Receipt Settings
                </x-filament::button>

                @php $previewId = $this->getPreviewSaleId(); @endphp
                @if($previewId)
                    {{-- Opens the real printed document for the latest sale, so
                         settings can be checked against actual paper. --}}
                    <x-filament::button
                        tag="a"
                        href="{{ route('pos.receipt', $previewId) }}"
                        target="_blank"
                        color="gray"
                        icon="heroicon-m-eye">
                        Preview on a real sale
                    </x-filament::button>
                @else
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Make a sale and a preview button will appear here.
                    </span>
                @endif
            </div>
        </form>
    </div>
</x-filament-panels::page>
