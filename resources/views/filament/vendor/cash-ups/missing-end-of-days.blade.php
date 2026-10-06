@php
    $days = $this->getMissingDays();
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
@endphp

<div>
@if ($days->isNotEmpty())
    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/30">
        <p class="font-semibold text-warning-800 dark:text-warning-200">
            {{ $days->count() }} {{ $days->count() === 1 ? 'day has' : 'days have' }} no End of Day record
        </p>
        <p class="mt-0.5 text-sm text-warning-700 dark:text-warning-300">
            Opened or sold on in the last {{ \App\Services\Cash\MissingEndOfDays::LOOKBACK_DAYS }} days, but never counted.
            The till asks the cashier to record each one before they start selling, or to say why not.
        </p>

        <ul class="mt-3 divide-y divide-warning-200 dark:divide-warning-800">
            @foreach ($days as $day)
                <li class="py-2 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                        <span class="font-medium text-gray-900 dark:text-gray-100">
                            {{ \Illuminate\Support\Carbon::parse($day['business_date'])->format('D d M') }}
                            — {{ $day['cashier_name'] }}
                            @if ($day['store_name'])
                                <span class="text-gray-500">at {{ $day['store_name'] }}</span>
                            @endif
                        </span>
                        <span class="text-gray-600 dark:text-gray-400">
                            @if ($day['sales_count'] > 0)
                                {{ $day['sales_count'] }} {{ $day['sales_count'] === 1 ? 'sale' : 'sales' }}, {{ $money($day['sales_total']) }}
                            @else
                                Opened, no sales
                            @endif
                            · {{ $day['session'] ? 'shift left open' : 'no shift opened' }}
                        </span>
                    </div>

                    @forelse ($day['reasons'] as $reason)
                        <p class="mt-1 text-gray-600 dark:text-gray-400">
                            <span class="text-gray-500">Put off on {{ \Illuminate\Support\Carbon::parse($reason['given_on'])->format('d M') }}:</span>
                            “{{ $reason['reason'] }}”
                        </p>
                    @empty
                        <p class="mt-1 text-gray-500">No reason given yet.</p>
                    @endforelse
                </li>
            @endforeach
        </ul>
    </div>
@endif
</div>
