<x-filament-panels::page>
@php
    $order   = $this->record->order;
    $product = $this->record->product;

    // Customer initials
    $parts    = explode(' ', trim($order->customer_name));
    $initials = strtoupper(($parts[0][0] ?? '') . ($parts[1][0] ?? ''));

    // Timeline stages
    $stages = [
        ['key' => 'placed',     'label' => 'Order placed',                         'sub' => $order->created_at->format('d M Y · g:i A')],
        ['key' => 'payment',    'label' => 'Payment method confirmed',               'sub' => match($order->payment_method) { 'pay_on_delivery' => 'Pay on Delivery', default => 'Paystack' }],
        ['key' => 'dispatched', 'label' => 'Rider notified & dispatched',            'sub' => null],
        ['key' => 'enroute',    'label' => 'Out for delivery',                       'sub' => null],
        ['key' => 'delivered',  'label' => 'Delivered & payment collected',           'sub' => null],
    ];

    $stageStatus = match($order->status) {
        'pending'               => ['placed' => 'done', 'payment' => 'active', 'dispatched' => '', 'enroute' => '', 'delivered' => ''],
        'confirmed'             => ['placed' => 'done', 'payment' => 'done',   'dispatched' => 'active', 'enroute' => '', 'delivered' => ''],
        'paid'                  => ['placed' => 'done', 'payment' => 'done',   'dispatched' => 'active', 'enroute' => '', 'delivered' => ''],
        'shipped'               => ['placed' => 'done', 'payment' => 'done',   'dispatched' => 'done', 'enroute' => 'active', 'delivered' => ''],
        'delivered'             => ['placed' => 'done', 'payment' => 'done',   'dispatched' => 'done', 'enroute' => 'done', 'delivered' => 'done'],
        'cancelled'             => ['placed' => 'done', 'payment' => 'done',   'dispatched' => '', 'enroute' => '', 'delivered' => ''],
        default                 => [],
    };

    $statusLabel = match($order->status) {
        'pending'               => 'Pending',
        'confirmed'             => 'Confirmed',
        'paid'                  => 'Paid',
        'shipped'               => 'Dispatched',
        'delivered'             => 'Delivered',
        'cancelled'             => 'Cancelled',
        'paid_but_failed_stock' => 'Stock Issue',
        default                 => ucfirst($order->status),
    };

    $statusColor = match($order->status) {
        'confirmed'             => 'bg-blue-100 text-blue-700',
        'paid', 'delivered'     => 'bg-emerald-100 text-emerald-700',
        'shipped'               => 'bg-amber-100 text-amber-700',
        'cancelled', 'paid_but_failed_stock' => 'bg-red-100 text-red-700',
        default                 => 'bg-gray-100 text-gray-600',
    };

    $lineTotal   = $this->record->quantity * $this->record->unit_price;
    $deliveryFee = 0;
@endphp

<div class="space-y-5" x-data="{ copied: false }">

    {{-- ── Status + Metrics row ─────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3 mb-1">
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full {{ $statusColor }}">
            <span class="w-1.5 h-1.5 rounded-full bg-current opacity-70"></span>
            {{ $statusLabel }}
        </span>
        @if($order->payment_method === 'pay_on_delivery')
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-amber-100 text-amber-700">
            Pay on Delivery
        </span>
        @else
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-emerald-100 text-emerald-700">
            Paystack
        </span>
        @endif
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl px-5 py-4 shadow-sm border border-gray-100 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-1">Order Total</p>
            <p class="text-xl font-bold text-emerald-600">₦{{ number_format($order->total_amount, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl px-5 py-4 shadow-sm border border-gray-100 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-1">Payment</p>
            <p class="text-base font-semibold text-amber-600 dark:text-amber-400">
                {{ $order->payment_method === 'pay_on_delivery' ? 'Pay on Delivery' : 'Paystack' }}
            </p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl px-5 py-4 shadow-sm border border-gray-100 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-1">Order Date</p>
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                {{ $order->created_at->format('d M Y, g:ia') }}
            </p>
        </div>
    </div>

    {{-- ── Two-column layout ────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- ── LEFT COLUMN ──────────────────────────────────────────────────── --}}
        <div class="space-y-5">

            {{-- Customer Details --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl p-5 shadow-sm border border-gray-100 dark:border-gray-800">
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <x-heroicon-o-user class="w-4 h-4 text-blue-500"/>
                    Customer Details
                </p>

                {{-- Avatar + name --}}
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-11 h-11 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 text-sm font-bold flex items-center justify-center flex-shrink-0">
                        {{ $initials }}
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ $order->customer_name }}</p>
                        <p class="text-xs text-gray-500">Customer</p>
                    </div>
                </div>

                {{-- Phone --}}
                <div class="flex items-start gap-3 mb-3">
                    <x-heroicon-o-phone class="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"/>
                    <div>
                        <p class="text-[11px] text-gray-400 mb-0.5">Phone Number</p>
                        <a href="tel:{{ $order->customer_phone }}"
                            class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline">
                            {{ $order->customer_phone }}
                        </a>
                    </div>
                </div>

                {{-- Email --}}
                <div class="flex items-start gap-3 mb-3">
                    <x-heroicon-o-envelope class="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"/>
                    <div>
                        <p class="text-[11px] text-gray-400 mb-0.5">Email</p>
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $order->customer_email }}</p>
                    </div>
                </div>

                {{-- Address --}}
                <div class="flex items-start gap-3 mb-5">
                    <x-heroicon-o-map-pin class="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"/>
                    <div>
                        <p class="text-[11px] text-gray-400 mb-0.5">Delivery Address</p>
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200 leading-relaxed">{{ $order->shipping_address }}</p>
                    </div>
                </div>

                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                    <div class="flex flex-wrap gap-2">
                        <a href="tel:{{ $order->customer_phone }}"
                            class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                            <x-heroicon-o-phone class="w-3.5 h-3.5"/>
                            Call
                        </a>
                        <a href="https://wa.me/{{ preg_replace('/\D/', '', $order->customer_phone) }}"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-2 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-colors">
                            <x-heroicon-o-chat-bubble-oval-left class="w-3.5 h-3.5"/>
                            WhatsApp
                        </a>
                        <button
                            x-on:click="navigator.clipboard.writeText('{{ addslashes($order->shipping_address) }}'); copied = true; setTimeout(() => copied = false, 2000)"
                            class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                            <x-heroicon-o-clipboard-document class="w-3.5 h-3.5"/>
                            <span x-text="copied ? 'Copied!' : 'Copy Address'">Copy Address</span>
                        </button>
                    </div>
                </div>
            </div>

        </div>

        {{-- ── RIGHT COLUMN ─────────────────────────────────────────────────── --}}
        <div class="space-y-5">

            {{-- Order Summary --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl p-5 shadow-sm border border-gray-100 dark:border-gray-800">
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <x-heroicon-o-shopping-bag class="w-4 h-4 text-blue-500"/>
                    Order Summary
                </p>

                @foreach($order->items as $item)
                <div class="flex items-center gap-3 py-2">
                    <div class="w-12 h-12 bg-gray-50 dark:bg-gray-800 rounded-lg flex items-center justify-center flex-shrink-0">
                        @php $img = $item->product?->getFirstMediaUrl('product-images', 'preview'); @endphp
                        @if($img)
                            <img src="{{ $img }}" alt="{{ $item->product->name }}" class="w-full h-full object-contain rounded-lg p-1">
                        @else
                            <x-heroicon-o-photo class="w-6 h-6 text-gray-300"/>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $item->product?->name ?? '—' }}</p>
                        <p class="text-xs text-gray-400">Qty: {{ $item->quantity }}</p>
                    </div>
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 flex-shrink-0">
                        ₦{{ number_format($item->unit_price * $item->quantity, 2) }}
                    </p>
                </div>
                @endforeach

                <div class="border-t border-gray-100 dark:border-gray-800 mt-3 pt-3 space-y-2">
                    <div class="flex justify-between text-sm text-gray-500">
                        <span>Subtotal</span>
                        <span>₦{{ number_format($order->items->sum(fn($i) => $i->unit_price * $i->quantity), 2) }}</span>
                    </div>
                    <div class="flex justify-between font-semibold text-base text-gray-900 dark:text-white border-t border-gray-100 dark:border-gray-800 pt-2 mt-1">
                        <span>Total</span>
                        <span class="text-emerald-600">₦{{ number_format($order->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Tracking Timeline --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl p-5 shadow-sm border border-gray-100 dark:border-gray-800">
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <x-heroicon-o-clock class="w-4 h-4 text-blue-500"/>
                    Tracking Timeline
                </p>

                @if($order->status === 'cancelled')
                <div class="flex items-center gap-3 py-2">
                    <div class="w-2.5 h-2.5 rounded-full bg-red-500 flex-shrink-0 ring-2 ring-red-200"></div>
                    <p class="text-sm font-medium text-red-600">Order cancelled</p>
                </div>
                @else
                <ul class="relative pl-5">
                    <li class="absolute left-[7px] top-2 bottom-2 w-px bg-gray-100 dark:bg-gray-800"></li>
                    @foreach($stages as $stage)
                    @php
                        $s = $stageStatus[$stage['key']] ?? '';
                        $dotClass = match($s) {
                            'done'   => 'bg-emerald-500 ring-2 ring-emerald-100 dark:ring-emerald-900',
                            'active' => 'bg-blue-500 ring-4 ring-blue-100 dark:ring-blue-900',
                            default  => 'bg-gray-200 dark:bg-gray-700',
                        };
                        $textClass = match($s) {
                            'done'   => 'text-gray-800 dark:text-gray-200 font-medium',
                            'active' => 'text-blue-600 dark:text-blue-400 font-medium',
                            default  => 'text-gray-400 dark:text-gray-600',
                        };
                    @endphp
                    <li class="relative mb-4 ml-3">
                        <span class="absolute -left-[22px] top-1 w-2.5 h-2.5 rounded-full {{ $dotClass }} z-10"></span>
                        <p class="text-sm {{ $textClass }}">{{ $stage['label'] }}</p>
                        @if($stage['sub'] && $s === 'done')
                        <p class="text-[11px] text-gray-400 mt-0.5">{{ $stage['sub'] }}</p>
                        @elseif($s === 'active')
                        <p class="text-[11px] text-blue-400 mt-0.5">In progress</p>
                        @endif
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>

        </div>
    </div>

</div>
</x-filament-panels::page>
