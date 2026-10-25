<div class="space-y-4 text-[13px]">
    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="p-3 bg-gray-50 dark:bg-white/5 rounded-xl border border-gray-200 dark:border-white/10">
            <span class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Affiliate</span>
            <p class="font-bold text-gray-900 dark:text-white mt-0.5">{{ $commission->affiliate?->code ?? '—' }}</p>
            <p class="text-[11px] text-gray-400 truncate">{{ $commission->affiliate?->user?->name ?? '' }}</p>
        </div>
        <div class="p-3 bg-gray-50 dark:bg-white/5 rounded-xl border border-gray-200 dark:border-white/10">
            <span class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Order</span>
            <p class="font-bold text-gray-900 dark:text-white mt-0.5">{{ $commission->order?->reference ?? '—' }}</p>
            <p class="text-[11px] text-gray-400">Total: ₦{{ number_format((float) ($commission->order?->total_amount ?? 0), 2) }}</p>
        </div>
        <div class="p-3 bg-gray-50 dark:bg-white/5 rounded-xl border border-gray-200 dark:border-white/10">
            <span class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total Commission</span>
            <p class="font-bold text-emerald-600 dark:text-emerald-400 text-[15px] mt-0.5">₦{{ number_format((float) $commission->amount, 2) }}</p>
            <p class="text-[11px] text-gray-400">Status: {{ ucfirst(str_replace('_', ' ', $commission->status)) }}</p>
        </div>
        <div class="p-3 bg-gray-50 dark:bg-white/5 rounded-xl border border-gray-200 dark:border-white/10">
            <span class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Affiliate Level</span>
            <p class="font-bold text-gray-900 dark:text-white mt-0.5">{{ $commission->affiliate?->level?->name ?? 'Base Level' }}</p>
            <p class="text-[11px] text-gray-400">{{ $commission->affiliate?->level ? $commission->affiliate->level->rate_value . 'x Multiplier' : 'Standard rate' }}</p>
        </div>
    </div>

    {{-- Line Items Table --}}
    <div class="border border-gray-200 dark:border-white/10 rounded-xl overflow-hidden">
        <div class="px-4 py-2.5 bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 font-semibold text-[12px] text-gray-700 dark:text-gray-300">
            Commission Calculation per Item
        </div>
        @if ($commission->items->isEmpty())
            <div class="p-4 text-center text-gray-500 text-[12px]">
                No line item breakdown recorded for this commission (total: ₦{{ number_format((float) $commission->amount, 2) }}).
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[12px]">
                    <thead class="bg-gray-50/50 dark:bg-white/5 text-gray-500 text-[11px] uppercase tracking-wider border-b border-gray-200 dark:border-white/10">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">Product</th>
                            <th class="px-3 py-2.5 font-semibold text-center">Qty</th>
                            <th class="px-3 py-2.5 font-semibold text-right">Sale Base</th>
                            <th class="px-3 py-2.5 font-semibold text-center">Rate Applied</th>
                            <th class="px-4 py-2.5 font-semibold text-right">Commission Earned</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($commission->items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-white">
                                    {{ $item->orderItem?->product?->name ?? 'Product #' . ($item->orderItem?->product_id ?? '—') }}
                                </div>
                                @if ($item->orderItem?->product?->category)
                                    <span class="text-[10px] text-gray-400">Category: {{ $item->orderItem->product->category->name }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">
                                {{ $item->orderItem?->quantity ?? 1 }}
                            </td>
                            <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300">
                                ₦{{ number_format((float) $item->base_amount, 2) }}
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                    {{ number_format((float) $item->rate, 2) }}%
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400">
                                ₦{{ number_format((float) $item->amount, 2) }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50/80 dark:bg-white/5 font-semibold border-t border-gray-200 dark:border-white/10">
                        <tr>
                            <td colspan="4" class="px-4 py-2.5 text-right text-gray-600 dark:text-gray-300">Total:</td>
                            <td class="px-4 py-2.5 text-right font-bold text-emerald-600 dark:text-emerald-400 text-[13px]">
                                ₦{{ number_format((float) $commission->amount, 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
