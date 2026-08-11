<x-filament-panels::page>

    <p class="text-sm text-gray-500 dark:text-gray-400 -mt-2 mb-4">
        Everything below is today's snapshot — tap a card for the full report.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($this->getCards() as $card)
            @php
                $borderClasses = match ($card->color()) {
                    'danger'  => 'border-danger-300 dark:border-danger-400/40',
                    'warning' => 'border-warning-300 dark:border-warning-400/40',
                    default   => 'border-gray-950/5 dark:border-white/10',
                };
                $badgeClasses = match ($card->color()) {
                    'danger'  => 'bg-danger-600 text-white',
                    'warning' => 'bg-warning-500 text-white',
                    default   => 'bg-success-600 text-white',
                };
            @endphp

            {{-- Whole card is clickable via a stretched link covering it, rather
                 than making the card itself an <a> — keeps this valid for both
                 the linked and unlinked (no detail page yet) cases without a
                 dynamic tag name. --}}
            <div @class([
                'relative rounded-xl border bg-white p-5 shadow-sm dark:bg-gray-900',
                $borderClasses,
                'transition-all hover:shadow-md hover:-translate-y-0.5' => $card->hasLink(),
                'opacity-75' => ! $card->hasLink(),
            ])>
                @if ($card->hasLink())
                    <a href="{{ $card->link }}" class="absolute inset-0 rounded-xl" aria-label="{{ $card->title }} — view full report"></a>
                @endif

                <div class="flex items-start justify-between gap-3 mb-2">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $card->title }}</p>
                    @if ($card->actionableCount !== null && $card->actionableCount > 0)
                        <span class="shrink-0 inline-flex items-center justify-center min-w-[1.5rem] h-6 px-1.5 rounded-full text-xs font-bold {{ $badgeClasses }}">
                            {{ $card->actionableCount }}
                        </span>
                    @endif
                </div>

                <p class="text-lg font-bold text-gray-900 dark:text-white leading-snug">{{ $card->headline }}</p>

                @if ($card->comparison)
                    <p class="mt-2 flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400">
                        @if ($card->comparisonDirection === 'up')
                            <span class="text-success-600 dark:text-success-400 font-bold">↑</span>
                        @elseif ($card->comparisonDirection === 'down')
                            <span class="text-danger-600 dark:text-danger-400 font-bold">↓</span>
                        @endif
                        <span>{{ $card->comparison }}</span>
                    </p>
                @endif

                @if ($card->note)
                    <p class="mt-3 text-xs italic text-gray-400 dark:text-gray-500">{{ $card->note }}</p>
                @endif
            </div>
        @endforeach
    </div>

</x-filament-panels::page>
