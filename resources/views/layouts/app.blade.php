<x-layouts::app.sidebar :title="$title ?? null">
        {{ $slot }}

        @auth
            @if(auth()->user()->hasRole('super_admin'))
                <livewire:custom-request-form />
            @endif
        @endauth
        
        @livewireScripts
</x-layouts::app.sidebar>
