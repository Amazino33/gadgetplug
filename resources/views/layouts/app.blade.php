<x-layouts::app.sidebar :title="$title ?? null">
        {{ $slot }}

        @auth
            @if(auth()->user()->hasRole('client_tester'))
                <livewire:custom-request-form />
            @endif
        @endauth
        
        @livewireScripts
</x-layouts::app.sidebar>
