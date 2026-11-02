{{-- How the shop's money came in, by tender. Shared by the Sales report and
     the Payment Methods report so the two can never word or colour it apart.
     Expects $payments from SalesReportService::paymentBreakdown(). --}}
@php
    $methodLabels = [
        'cash' => 'Cash',
        'card' => 'Card (POS)',
        'bank_transfer' => 'Bank transfer',
        'debt' => 'On credit',
        'store_credit' => 'Store credit',
    ];
    $methodColours = [
        'cash' => 'bg-emerald-500',
        'card' => 'bg-violet-500',
        'bank_transfer' => 'bg-blue-500',
        'debt' => 'bg-amber-500',
    ];
    $totalTendered = array_sum($payments['sales']);
    $totalRepaid = array_sum($payments['repayments']);
    $describe = fn (array $amounts): string => collect($amounts)
        ->filter(fn ($amount) => $amount > 0)
        ->map(fn ($amount, $method) => ($methodLabels[$method] ?? ucfirst(str_replace('_', ' ', $method))) . ' ₦' . number_format($amount, 2))
        ->implode(', ');
@endphp
<div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Payment methods</h3>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Shop sales by how the customer paid. Includes VAT, so the total can be higher than shop revenue above.
    </p>

    @if ($totalTendered <= 0 && $totalRepaid <= 0)
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No shop sales in this period.</p>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($payments['sales'] as $method => $amount)
                {{-- Every tender but credit is listed even at ₦0: "no card sales" is an answer. --}}
                @continue($method === 'debt' && $amount <= 0)
                @php $share = $totalTendered > 0 ? ($amount / $totalTendered) * 100 : 0; @endphp
                <div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-700 dark:text-gray-300">
                            {{ $methodLabels[$method] }}
                            @if ($method === 'debt')
                                <span class="ml-1 text-xs text-warning-600 dark:text-warning-400">still owed, not received</span>
                            @endif
                        </span>
                        <span class="font-semibold text-gray-950 dark:text-white">
                            ₦{{ number_format($amount, 2) }}
                            <span class="ml-1 text-xs font-normal text-gray-500">({{ number_format($share, 1) }}%)</span>
                        </span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-full rounded-full {{ $methodColours[$method] }}" style="width: {{ $share }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($totalRepaid > 0 || ! empty($payments['refunds']))
            <div class="mt-4 space-y-1 rounded-lg bg-gray-50 p-3 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                @if ($totalRepaid > 0)
                    <p>Old debts paid back this period, not counted above: {{ $describe($payments['repayments']) }}.</p>
                @endif
                @if (! empty($payments['refunds']))
                    <p>Refunded to customers this period: {{ $describe($payments['refunds']) }}.</p>
                @endif
            </div>
        @endif
    @endif
</div>
