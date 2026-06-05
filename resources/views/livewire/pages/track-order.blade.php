<?php

use App\Models\Order;
use Livewire\Volt\Component;

new class extends Component {
    public string $reference = '';
    public ?Order $order     = null;
    public ?string $error    = null;

    public function mount(): void
    {
        $ref = request()->query('ref');
        if ($ref) {
            $this->reference = $ref;
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $this->error = null;
        $this->order = null;

        $ref = trim($this->reference);

        if (blank($ref)) {
            $this->error = 'Please enter an order reference number.';
            return;
        }

        $order = Order::with('items.product')->where('reference', $ref)->first();

        if (! $order) {
            $this->error = 'No order found with that reference. Double-check and try again.';
            return;
        }

        $this->order = $order;
    }
}; ?>

<x-layouts.storefront title="Track Your Order — GadgetPlug">
<div class="min-h-[calc(100vh-120px)] bg-brand-bg dark:bg-[#0d1a0d]">
<div class="max-w-[680px] mx-auto px-4 md:px-6 py-10 md:py-14">

    {{-- Page heading --}}
    <div class="mb-7 text-center">
        <div class="w-12 h-12 bg-brand rounded-2xl flex items-center justify-center mx-auto mb-4">
            <svg class="w-6 h-6 text-white fill-none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
        </div>
        <h1 class="font-montserrat font-black text-[22px] md:text-[26px] text-brand-dark dark:text-[#e8f5e9]">Track Your Order</h1>
        <p class="text-[13px] text-brand-muted mt-1">Enter your order reference to see the latest status.</p>
    </div>

    {{-- Search card --}}
    <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6 mb-5">
        <form wire:submit="lookup" class="flex gap-2.5">
            <div class="flex-1">
                <input wire:model="reference"
                    type="text"
                    placeholder="e.g. GP-KHMZ4CAUOK"
                    class="w-full h-11 px-4 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-brand-dark dark:text-[#e8f5e9] placeholder:text-brand-muted focus:outline-none focus:border-brand transition-colors font-mono tracking-wide">
            </div>
            <button type="submit"
                class="h-11 px-5 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors flex-shrink-0 flex items-center gap-2">
                <span wire:loading.remove wire:target="lookup">Track</span>
                <span wire:loading wire:target="lookup" class="flex items-center gap-2">
                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    Checking…
                </span>
            </button>
        </form>

        @if($error)
        <div class="mt-3 flex items-center gap-2.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-4 py-2.5">
            <svg class="w-4 h-4 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <p class="text-[12px] text-red-600 dark:text-red-400 font-medium">{{ $error }}</p>
        </div>
        @endif
    </div>

    {{-- Results --}}
    @if($order)
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
            'cancelled', 'paid_but_failed_stock' => 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400',
            default                 => 'bg-gray-100 text-gray-600 dark:bg-[#1a2a1a] dark:text-[#b0c8b0]',
        };

        $stages = [
            ['key' => 'placed',     'label' => 'Order Placed',           'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['key' => 'confirmed',  'label' => 'Payment Confirmed',       'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
            ['key' => 'processing', 'label' => 'Processing & Packaging',  'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10'],
            ['key' => 'shipped',    'label' => 'Out for Delivery',        'icon' => 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7'],
            ['key' => 'delivered',  'label' => 'Delivered',               'icon' => 'M5 13l4 4L19 7'],
        ];

        $stageState = match($order->status) {
            'pending'               => ['placed' => 'done', 'confirmed' => 'active', 'processing' => '', 'shipped' => '', 'delivered' => ''],
            'confirmed'             => ['placed' => 'done', 'confirmed' => 'done',   'processing' => 'active', 'shipped' => '', 'delivered' => ''],
            'paid'                  => ['placed' => 'done', 'confirmed' => 'done',   'processing' => 'active', 'shipped' => '', 'delivered' => ''],
            'shipped'               => ['placed' => 'done', 'confirmed' => 'done',   'processing' => 'done',   'shipped' => 'active', 'delivered' => ''],
            'delivered'             => ['placed' => 'done', 'confirmed' => 'done',   'processing' => 'done',   'shipped' => 'done',   'delivered' => 'done'],
            default                 => ['placed' => 'done', 'confirmed' => '',        'processing' => '',       'shipped' => '',       'delivered' => ''],
        };
    @endphp

    {{-- Order meta --}}
    <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden mb-4">
        <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a] flex items-center justify-between gap-3">
            <div>
                <p class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">{{ $order->reference }}</p>
                <p class="text-[11px] text-brand-muted mt-0.5">Placed {{ $order->created_at->format('d M Y, g:ia') }}</p>
            </div>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-[0.5px] {{ $statusClass }}">
                {{ $statusLabel }}
            </span>
        </div>

        <div class="px-5 md:px-6 py-4 grid grid-cols-2 gap-4 border-b border-brand-border dark:border-[#2a3a2a]">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.6px] text-brand-muted mb-0.5">Payment</p>
                <p class="text-[13px] font-semibold text-brand-dark dark:text-[#e8f5e9]">
                    {{ $order->payment_method === 'pay_on_delivery' ? 'Pay on Delivery' : 'Paystack' }}
                </p>
            </div>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.6px] text-brand-muted mb-0.5">Total</p>
                <p class="font-montserrat font-black text-[16px] text-brand">₦{{ number_format($order->total_amount) }}</p>
            </div>
            <div class="col-span-2">
                <p class="text-[10px] font-semibold uppercase tracking-[0.6px] text-brand-muted mb-0.5">Delivery Address</p>
                <p class="text-[13px] text-brand-dark dark:text-[#e8f5e9] leading-relaxed">{{ $order->shipping_address }}</p>
            </div>
        </div>

        {{-- Items --}}
        <div class="divide-y divide-brand-border dark:divide-[#2a3a2a]">
            @foreach($order->items as $item)
            <div class="px-5 md:px-6 py-3.5 flex items-center gap-3">
                <div class="w-11 h-11 bg-brand-bg dark:bg-[#0d1a0d] rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
                    @php $img = $item->product?->getFirstMediaUrl('product-images', 'preview'); @endphp
                    @if($img)
                        <img src="{{ $img }}" alt="{{ $item->product->name }}" class="w-full h-full object-contain p-1">
                    @else
                        <svg class="w-5 h-5 text-brand-muted fill-none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-[13px] font-medium text-brand-dark dark:text-[#e8f5e9] truncate">{{ $item->product?->name ?? 'Product' }}</p>
                    <p class="text-[11px] text-brand-muted">Qty: {{ $item->quantity }}</p>
                </div>
                <p class="font-montserrat font-bold text-[13px] text-brand-dark dark:text-[#e8f5e9] flex-shrink-0">
                    ₦{{ number_format($item->unit_price * $item->quantity) }}
                </p>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Timeline --}}
    @if($order->status !== 'cancelled' && $order->status !== 'paid_but_failed_stock')
    <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
        <h3 class="font-montserrat font-bold text-[13px] text-brand-dark dark:text-[#e8f5e9] mb-5 uppercase tracking-[0.6px]">Tracking Timeline</h3>

        <div class="relative">
            {{-- Vertical line --}}
            <div class="absolute left-[15px] top-4 bottom-4 w-px bg-brand-border dark:bg-[#2a3a2a]"></div>

            <div class="space-y-5">
                @foreach($stages as $stage)
                @php
                    $s = $stageState[$stage['key']] ?? '';
                    $isDone   = $s === 'done';
                    $isActive = $s === 'active';
                @endphp
                <div class="flex items-start gap-4 relative">
                    {{-- Dot --}}
                    <div class="w-[30px] h-[30px] rounded-full flex-shrink-0 flex items-center justify-center z-10
                        {{ $isDone   ? 'bg-brand' : ($isActive ? 'bg-blue-600' : 'bg-brand-bg dark:bg-[#1a2a1a] border-2 border-brand-border dark:border-[#2a3a2a]') }}">
                        @if($isDone)
                            <svg class="w-3.5 h-3.5 text-white fill-none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stage['icon'] }}"/>
                            </svg>
                        @elseif($isActive)
                            <span class="w-2 h-2 bg-white rounded-full animate-pulse"></span>
                        @else
                            <span class="w-2 h-2 bg-brand-border dark:bg-[#3a4a3a] rounded-full"></span>
                        @endif
                    </div>

                    {{-- Label --}}
                    <div class="pt-1">
                        <p class="text-[13px] font-semibold
                            {{ $isDone ? 'text-brand-dark dark:text-[#e8f5e9]' : ($isActive ? 'text-blue-600 dark:text-blue-400' : 'text-brand-muted') }}">
                            {{ $stage['label'] }}
                        </p>
                        @if($isDone && $loop->first)
                            <p class="text-[11px] text-brand-muted mt-0.5">{{ $order->created_at->format('d M Y · g:ia') }}</p>
                        @elseif($isActive)
                            <p class="text-[11px] text-blue-500 dark:text-blue-400 mt-0.5">In progress…</p>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @else
    <div class="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 rounded-2xl p-5 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5 fill-none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <div>
            <p class="font-montserrat font-bold text-[13px] text-red-700 dark:text-red-400">
                {{ $order->status === 'cancelled' ? 'Order Cancelled' : 'Action Required' }}
            </p>
            <p class="text-[12px] text-red-600 dark:text-red-400 mt-0.5">
                {{ $order->status === 'cancelled'
                    ? 'This order has been cancelled. Contact us at lukratif1@gmail.com if you need help.'
                    : 'There was a stock issue with your order. Please contact us at lukratif1@gmail.com.' }}
            </p>
        </div>
    </div>
    @endif

    @endif

    {{-- Footer help --}}
    <p class="text-center text-[11px] text-brand-muted mt-6">
        Need help? Email us at
        <a href="mailto:lukratif1@gmail.com" class="text-brand hover:underline font-medium">lukratif1@gmail.com</a>
    </p>

</div>
</div>
</x-layouts.storefront>
