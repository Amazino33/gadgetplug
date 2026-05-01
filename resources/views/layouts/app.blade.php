<x-layouts::app.sidebar :title="$title ?? null">
        {{ $slot }}
        <livewire:custom-request-form />
        
        @livewireScripts
</x-layouts::app.sidebar>
