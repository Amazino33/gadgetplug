<x-filament-widgets::widget>
    @if ($tills->isNotEmpty())
        <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
            <p class="font-bold">
                {{ $tills->count() === 1 ? 'A till' : $tills->count() . ' tills' }} at this branch still {{ $tills->count() === 1 ? 'holds' : 'hold' }} sales that have not reached the server.
            </p>
            <p class="mt-1">
                A sale rung before a count but not yet synced is missing from the figures below. Ask the cashier to get the till online first.
                If you approve now, the result shows "Re-check" once the sale arrives.
            </p>
            <ul class="mt-2 list-disc ps-5">
                @foreach ($tills as $till)
                    <li>
                        {{ $till->user?->name ?? 'A till' }}: {{ $till->pending_sales }} sale{{ $till->pending_sales === 1 ? '' : 's' }}
                        @if ($till->oldest_pending_at)
                            , the oldest rung {{ $till->oldest_pending_at->timezone('Africa/Lagos')->format('D j M, g:ia') }}
                        @endif
                        — last heard from {{ $till->reported_at->timezone('Africa/Lagos')->diffForHumans() }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-filament-widgets::widget>
