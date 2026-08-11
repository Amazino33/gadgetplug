<x-filament-panels::page>

    {{ $this->form }}

    <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Fastest-Moving Products</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $period->label }} — {{ $period->from->format('d M Y') }} to {{ $period->to->format('d M Y') }}.
                Ranked by units sold, both online and in-store, highest first.
            </p>
        </div>

        @if ($rows->isEmpty())
            <div class="px-6 pb-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing sold in this period yet.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-t border-gray-100 dark:border-white/10">
                    <thead>
                        <tr class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <th class="px-6 py-3">#</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3 text-right">Units Sold</th>
                            <th class="px-4 py-3 text-right">Daily Velocity</th>
                            <th class="px-4 py-3 text-right">Revenue</th>
                            <th class="px-6 py-3 text-right">Current Stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $index => $row)
                            <tr class="text-sm">
                                <td class="px-6 py-3 text-gray-400 dark:text-gray-500">{{ $index + 1 }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row->product->name }}</p>
                                    @if ($row->product->sku)
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $row->product->sku }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $row->product->category?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{{ number_format($row->unitsSold) }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($row->dailyVelocity, 2) }}/day</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">₦{{ number_format($row->revenue, 2) }}</td>
                                <td class="px-6 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($row->product->stock_quantity) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</x-filament-panels::page>
