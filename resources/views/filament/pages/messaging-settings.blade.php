<x-filament-panels::page>
    @php $h = $this->health(); @endphp

    {{-- The WhatsApp brake: paused or running, and today's sending against the cap. --}}
    <div @class([
        'rounded-xl p-4 ring-1',
        'bg-danger-50 ring-danger-200 dark:bg-danger-500/10 dark:ring-danger-500/30' => $h['paused'],
        'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => ! $h['paused'],
    ])>
        <p class="text-sm font-semibold text-gray-950 dark:text-white">
            WhatsApp (Wawp): {{ $h['paused'] ? 'PAUSED' : 'Sending' }}
        </p>
        @if ($h['paused'])
            <p class="mt-1 text-sm text-danger-700 dark:text-danger-400">{{ $h['pause_reason'] }} Urgent messages are going by SMS; {{ $h['held'] }} other {{ \Illuminate\Support\Str::plural('message', $h['held']) }} waiting.</p>
        @endif
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">Last success</dt><dd class="font-medium">{{ $h['last_success']?->diffForHumans() ?? 'Never' }}</dd></div>
            <div><dt class="text-gray-500">Failures today</dt><dd class="font-medium">{{ $h['failures_today'] }}</dd></div>
            <div><dt class="text-gray-500">Sent today</dt><dd class="font-medium">{{ $h['sent_today'] }} / {{ $h['daily_cap'] }}{{ $h['warmup'] ? ' (warm-up)' : '' }}</dd></div>
            <div><dt class="text-gray-500">Failures in a row</dt><dd class="font-medium">{{ $h['failures_row'] }}</dd></div>
        </dl>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit">
                Save Settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>