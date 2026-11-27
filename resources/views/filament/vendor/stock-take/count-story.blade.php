@php
    use App\Support\Quantity;

    $tz   = \App\Services\StockTake\StockTakeStory::TZ;
    $q    = fn ($n) => Quantity::format($n);
    $sign = fn ($n) => ((float) $n > 0 ? '+' : ((float) $n < 0 ? '−' : '')) . Quantity::format(abs((float) $n));
    $countDay = $s['counted_at']->copy()->setTimezone($tz)->toDateString();
    // The time, with the day when it is not the day of the count.
    $time = fn ($t) => $t->copy()->setTimezone($tz)->toDateString() === $countDay
        ? $t->copy()->setTimezone($tz)->format('g:ia')
        : $t->copy()->setTimezone($tz)->format('D j M, g:ia');
    $unit = strtolower($s['base_unit']);
    $diff = (float) $s['difference'];
    $diffText = $diff == 0 ? 'matches the books' : ($diff < 0 ? Quantity::format(abs($diff)) . ' missing' : Quantity::format($diff) . ' more than the books');
    $badSaid = collect([
        (float) $s['damaged'] > 0 ? $q($s['damaged']) . ' damaged' : null,
        (float) $s['expired'] > 0 ? $q($s['expired']) . ' expired' : null,
    ])->filter()->implode(' and ');
@endphp

<div class="space-y-5 text-sm">
    {{-- The answer first, in one sentence. --}}
    <div class="rounded-xl p-4 {{ $diff == 0 ? 'bg-success-50 dark:bg-success-500/10' : ($diff < 0 ? 'bg-danger-50 dark:bg-danger-500/10' : 'bg-warning-50 dark:bg-warning-500/10') }}">
        <p class="text-base font-semibold text-gray-950 dark:text-white">
            At {{ $time($s['counted_at']) }} on {{ $s['counted_at']->copy()->setTimezone($tz)->format('D j M') }},
            {{ $s['counted_by'] }} counted <strong>{{ $q($s['counted']) }}</strong>{{ $badSaid ? ' (plus ' . $badSaid . ')' : '' }}.
            The books said <strong>{{ $q($s['books']) }}</strong>:
            <strong class="{{ $diff == 0 ? 'text-success-700 dark:text-success-400' : ($diff < 0 ? 'text-danger-700 dark:text-danger-400' : 'text-warning-700 dark:text-warning-400') }}">{{ $diffText }}</strong>.
        </p>
        @if (count($s['sellers']) > 0)
            <p class="mt-2 text-gray-700 dark:text-gray-300">
                Sold at the till before the count:
                {{ collect($s['sellers'])->map(fn ($p) => $p['name'] . ' ' . $q($p['qty']) . ' (' . $p['sales'] . ' ' . ($p['sales'] === 1 ? 'sale' : 'sales') . ')')->implode(', ') }}.
            </p>
        @endif
        @if ($s['used_at_approval'] !== null)
            <p class="mt-2 text-gray-700 dark:text-gray-300">
                When it was approved the books said {{ $q($s['used_at_approval']) }}; a till sale from before the count arrived later. Re-check corrects that.
            </p>
        @endif
    </div>

    {{-- Where it was counted, when there is more than one place. --}}
    @if (count($s['counts']) > 1)
        <div>
            <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Counted in</h3>
            <ul class="space-y-0.5 text-gray-700 dark:text-gray-300">
                @foreach ($s['counts'] as $c)
                    <li>{{ $c['aisle'] }}: <strong>{{ $q($c['qty']) }}</strong> · {{ $time($c['at']) }} · {{ $c['by'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- How the books got there: the day so far, line by line. --}}
    <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">How the books got to {{ $q($s['books']) }}</h3>
        <table class="w-full table-fixed"><colgroup><col class="w-36"><col><col class="w-16"><col class="w-16"></colgroup>
            <thead>
                <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                    <th class="pb-1 pe-2 font-medium">Time</th>
                    <th class="pb-1 pe-2 font-medium">What happened</th>
                    <th class="pb-1 pe-2 text-end font-medium">Qty</th>
                    <th class="pb-1 text-end font-medium">Books</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                <tr class="text-gray-600 dark:text-gray-400">
                    <td class="py-1.5 pe-2 tabular-nums">{{ $time($s['day_start']) }}</td>
                    <td class="py-1.5 pe-2">Start of the day</td>
                    <td class="py-1.5 pe-2"></td>
                    <td class="py-1.5 text-end font-semibold tabular-nums text-gray-950 dark:text-white">{{ $q($s['opening']) }}</td>
                </tr>
                @if ($s['hidden_before'] > 0)
                    <tr class="text-gray-500"><td></td><td colspan="3" class="py-1.5">… {{ $s['hidden_before'] }} earlier movements (in the figures, not listed)</td></tr>
                @endif
                @forelse ($s['before'] as $row)
                    <tr>
                        <td class="py-1.5 pe-2 tabular-nums text-gray-600 dark:text-gray-400">{{ $time($row['at']) }}</td>
                        <td class="py-1.5 pe-2 text-gray-950 dark:text-white">
                            {{ $row['what'] }}@if ($row['by']) <span class="text-gray-500 dark:text-gray-400">· {{ $row['by'] }}</span>@endif
                            @if ($row['ref']) <span class="text-xs text-gray-400">{{ $row['ref'] }}</span>@endif
                        </td>
                        <td class="py-1.5 pe-2 text-end font-semibold tabular-nums {{ (float) $row['qty'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">{{ $sign($row['qty']) }}</td>
                        <td class="py-1.5 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $q($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td></td><td colspan="3" class="py-1.5 text-gray-500">Nothing moved between the start of the day and the count.</td></tr>
                @endforelse
                <tr class="bg-primary-50 dark:bg-primary-500/10">
                    <td class="py-2 pe-2 font-semibold tabular-nums">{{ $time($s['counted_at']) }}</td>
                    <td class="py-2 pe-2 font-semibold text-gray-950 dark:text-white">{{ $s['counted_by'] }} counted {{ $q($s['present']) }}{{ $badSaid ? ' (' . $q($s['counted']) . ' good, ' . $badSaid . ')' : '' }}</td>
                    <td class="py-2 pe-2 text-end font-bold tabular-nums {{ $diff < 0 ? 'text-danger-600 dark:text-danger-400' : ($diff > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400') }}">{{ $diff == 0 ? '✓' : $sign($diff) }}</td>
                    <td class="py-2 text-end font-bold tabular-nums text-gray-950 dark:text-white">{{ $q($s['books']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- After the count: shown for the full picture, never part of the check. --}}
    @if (count($s['after']) > 0)
        <div>
            <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">After the count</h3>
            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">The shop kept selling. None of this is compared with the count.</p>
            <table class="w-full table-fixed text-gray-600 dark:text-gray-400"><colgroup><col class="w-36"><col><col class="w-16"><col class="w-16"></colgroup>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($s['after'] as $row)
                        <tr>
                            <td class="py-1.5 pe-2 tabular-nums">{{ $time($row['at']) }}</td>
                            <td class="py-1.5 pe-2">{{ $row['what'] }}@if ($row['by']) · {{ $row['by'] }}@endif</td>
                            <td class="py-1.5 pe-2 text-end tabular-nums">{{ $sign($row['qty']) }}</td>
                            <td class="py-1.5 text-end tabular-nums">{{ $q($row['balance']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="text-gray-600 dark:text-gray-400">On the books now: <strong class="text-gray-950 dark:text-white">{{ $q($s['now']) }} {{ (float) $s['now'] === 1.0 ? $unit : \Illuminate\Support\Str::plural($unit) }}</strong></p>
</div>
