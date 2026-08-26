<x-filament-panels::page>
    {{-- The three figures the whole page resolves to, before the detail. --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Sold on credit</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                ₦{{ number_format($summary['charged'], 2) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Paid</div>
            <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                ₦{{ number_format($summary['paid'], 2) }}
            </div>
            @if ($summary['written_off'] > 0)
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Plus ₦{{ number_format($summary['written_off'], 2) }} written off
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Still owing</div>
            <div class="text-2xl font-bold {{ $summary['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-900 dark:text-white' }}">
                ₦{{ number_format($summary['outstanding'], 2) }}
            </div>
            @if ($summary['outstanding'] < 0)
                {{-- Say it plainly rather than showing a negative and letting
                     somebody chase a customer who is actually in credit. --}}
                <div class="text-xs text-success-600 dark:text-success-400 mt-1">
                    In credit — the store owes them ₦{{ number_format(abs($summary['outstanding']), 2) }}
                </div>
            @endif
        </x-filament::section>
    </div>

    @if ($customer->shop_location || $customer->address || $customer->notes)
        <x-filament::section heading="Where to find them" collapsible collapsed>
            <div class="space-y-1 text-sm text-gray-700 dark:text-gray-300">
                @if ($customer->shop_location)
                    <div><span class="text-gray-500 dark:text-gray-400">Shop:</span> {{ $customer->shop_location }}</div>
                @endif
                @if ($customer->address)
                    <div><span class="text-gray-500 dark:text-gray-400">Address:</span> {{ $customer->address }}</div>
                @endif
                @if ($customer->notes)
                    <div class="pt-1 text-gray-600 dark:text-gray-400">{{ $customer->notes }}</div>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="History">
        @if ($history->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing on this customer's account yet.</p>
        @else
            {{-- Scrolls inside itself so a long statement never pushes the page
                 sideways on a phone at the counter. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4 font-medium">Date</th>
                            <th class="py-2 pr-4 font-medium">What</th>
                            <th class="py-2 pr-4 font-medium">Staff</th>
                            <th class="py-2 pr-4 font-medium">Store</th>
                            <th class="py-2 pr-4 font-medium text-right">Amount</th>
                            <th class="py-2 font-medium text-right">Balance after</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $line)
                            @php($entry = $line['entry'])
                            <tr class="border-b border-gray-100 dark:border-gray-800 last:border-0">
                                <td class="py-2 pr-4 whitespace-nowrap text-gray-700 dark:text-gray-300">
                                    {{ $entry->occurred_at->format('d M Y') }}
                                </td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">
                                    <span @class([
                                        'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                        'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-400' => $entry->isCharge(),
                                        'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-400' => $entry->isPayment(),
                                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $entry->isWriteoff(),
                                    ])>
                                        {{ $entry->isCharge() ? 'Credit sale' : ($entry->isPayment() ? 'Payment' : 'Written off') }}
                                    </span>
                                    @if ($entry->description)
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $entry->description }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                    {{ $entry->creator?->name ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                    {{ $entry->store?->name ?? '—' }}
                                </td>
                                <td @class([
                                    'py-2 pr-4 text-right whitespace-nowrap font-medium',
                                    'text-danger-600 dark:text-danger-400' => $entry->isCharge(),
                                    'text-success-600 dark:text-success-400' => ! $entry->isCharge(),
                                ])>
                                    {{ $entry->isCharge() ? '+' : '−' }}₦{{ number_format(abs((float) $entry->amount), 2) }}
                                </td>
                                <td class="py-2 text-right whitespace-nowrap font-bold text-gray-900 dark:text-white">
                                    ₦{{ number_format($line['running'], 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
