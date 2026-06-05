<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Order;

new class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'orders' => Order::where('user_id', auth()->id())
                ->latest()
                ->paginate(10),
        ];
    }
}; ?>

<div>
<x-layouts.account active="account.orders">

    <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
        <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a] flex items-center justify-between gap-3">
            <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Order History</h2>
            <a href="{{ route('track-order') }}"
               class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-brand hover:underline">
                <svg class="w-3.5 h-3.5 fill-none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                Track an Order
            </a>
        </div>

        @if ($orders->isEmpty())
        <div class="px-6 py-14 text-center">
            <div class="text-5xl mb-3">📦</div>
            <h4 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9] mb-1">No orders yet</h4>
            <p class="text-[12px] text-brand-muted mb-4">Your order history will appear here once you make a purchase.</p>
            <a href="{{ route('home') }}"
               class="inline-block bg-brand text-white font-montserrat font-bold text-[12px] px-5 py-2 rounded-xl hover:bg-[#055002] transition-colors">
                Start Shopping
            </a>
        </div>
        @else
        <div class="divide-y divide-brand-border dark:divide-[#2a3a2a]">
            @foreach ($orders as $order)
            <div class="px-5 md:px-6 py-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
                    <div>
                        <span class="font-montserrat font-bold text-[13px] text-brand-dark dark:text-[#e8f5e9]">
                            {{ $order->reference }}
                        </span>
                        <span class="text-[11px] text-brand-muted ml-2">
                            {{ $order->created_at->format('d M Y, g:ia') }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        @php
                        $statusLabel = match($order->status) {
                            'pending'               => 'Pending Payment',
                            'confirmed'             => 'Confirmed',
                            'paid'                  => 'Paid',
                            'shipped'               => 'On the Way',
                            'delivered'             => 'Delivered',
                            'cancelled'             => 'Cancelled',
                            'paid_but_failed_stock' => 'Action Needed',
                            default                 => ucfirst($order->status),
                        };
                        $statusClass = match($order->status) {
                            'paid', 'delivered'     => 'bg-[#e8f5e8] text-brand dark:bg-[#1a2a1a] dark:text-brand-lime',
                            'confirmed'             => 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400',
                            'shipped'               => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                            'pending'               => 'bg-gray-100 text-gray-600 dark:bg-[#1a2a1a] dark:text-[#b0c8b0]',
                            'cancelled', 'paid_but_failed_stock' => 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400',
                            default                 => 'bg-gray-100 text-gray-600 dark:bg-[#1a2a1a] dark:text-[#b0c8b0]',
                        };
                        @endphp
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-[0.5px] {{ $statusClass }}">
                            {{ $statusLabel }}
                        </span>
                        <span class="text-[11px] text-brand-muted">via {{ ucfirst(str_replace('_', ' ', $order->payment_method)) }}</span>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-1">
                    <p class="text-[12px] text-brand-muted leading-relaxed">
                        {{ $order->shipping_address }}
                    </p>
                    <span class="font-montserrat font-black text-[16px] text-brand shrink-0">
                        ₦{{ number_format($order->total_amount) }}
                    </span>
                </div>
            </div>
            @endforeach
        </div>

        @if ($orders->hasPages())
        <div class="px-5 md:px-6 py-4 border-t border-brand-border dark:border-[#2a3a2a]">
            {{ $orders->links() }}
        </div>
        @endif
        @endif
    </div>

</x-layouts.account>
</div>
