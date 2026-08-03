@php
    $statusLabel = match ($order->status) {
        'pending'               => 'Pending',
        'confirmed'             => 'Confirmed',
        'paid'                  => 'Paid',
        'shipped'               => 'Dispatched',
        'delivered'             => 'Delivered',
        'cancelled'             => 'Cancelled',
        'paid_but_failed_stock' => 'Stock Issue',
        default                 => ucfirst($order->status),
    };
    $statusClasses = match ($order->status) {
        'confirmed'                          => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        'paid', 'delivered'                  => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        'shipped'                            => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
        'cancelled', 'paid_but_failed_stock'  => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        default                              => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    };
    $paymentLabel = $order->payment_method === 'pay_on_delivery' ? 'Pay on Delivery' : 'Paystack';
    $paymentClasses = $order->payment_method === 'pay_on_delivery'
        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400'
        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400';

    $items = $order->items;
    $firstProduct = $items->first()?->product?->name;
    $remainingCount = $items->count() - 1;
    $productSummary = $items->isEmpty()
        ? '—'
        : ($items->count() > 1 ? "{$firstProduct} +{$remainingCount} more" : $firstProduct);

    $statusOptions = $this->statusOptionsFor($order);

    $viewUrl = \App\Filament\Vendor\Resources\Orders\OrderResource::getUrl('view', ['record' => $order], tenant: filament()->getTenant());
@endphp

<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4">
    <a href="{{ $viewUrl }}" class="mb-3 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-montserrat text-[15px] font-bold text-gray-900 dark:text-white">{{ $order->reference }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $order->created_at->format('d M Y, g:ia') }}</p>
        </div>
        <span class="shrink-0 inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold {{ $statusClasses }}">{{ $statusLabel }}</span>
    </a>

    <div class="space-y-1.5 border-t border-gray-100 dark:border-white/5 pt-3 text-sm">
        <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-user class="h-4 w-4 shrink-0 text-gray-400"/>
            <span class="truncate font-medium text-gray-900 dark:text-white">{{ $order->customer_name }}</span>
            <span class="text-gray-400">·</span>
            <span class="truncate text-gray-500 dark:text-gray-400">{{ $order->customer_phone }}</span>
        </div>

        <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-map-pin class="h-4 w-4 shrink-0 text-gray-400"/>
            <span class="truncate">{{ $order->local_government ?? 'No LGA on record' }}</span>
        </div>

        <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
            <x-heroicon-o-shopping-bag class="h-4 w-4 shrink-0 text-gray-400"/>
            <span class="truncate">{{ $productSummary }}</span>
        </div>
    </div>

    <div class="mt-3 flex items-end justify-between border-t border-gray-100 dark:border-white/5 pt-3">
        <div>
            <span class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold {{ $paymentClasses }}">{{ $paymentLabel }}</span>
            <p class="mt-1.5 font-montserrat text-base font-bold text-gray-900 dark:text-white">₦{{ number_format((float) $order->total_amount, 2) }}</p>
        </div>
        <div class="flex items-center gap-1">
            <a href="{{ $viewUrl }}" title="View" aria-label="View order {{ $order->reference }}"
                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 dark:hover:bg-white/10 hover:text-gray-700 dark:hover:text-white">
                <x-heroicon-o-eye class="h-4 w-4"/>
            </a>
            <a href="tel:{{ $order->customer_phone }}"
                title="Call" aria-label="Call {{ $order->customer_name }}"
                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-600">
                <x-heroicon-o-phone class="h-4 w-4"/>
            </a>
            <a href="https://api.whatsapp.com/send?phone={{ preg_replace('/\D/', '', $order->customer_phone) }}" target="_blank"
                title="WhatsApp" aria-label="WhatsApp {{ $order->customer_name }}"
                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-600">
                <x-heroicon-o-chat-bubble-oval-left class="h-4 w-4"/>
            </a>
            <button type="button"
                x-data
                x-on:click="window.dispatchEvent(new CustomEvent('open-update-status', { detail: { id: {{ $order->id }}, options: @js($statusOptions) } }))"
                title="Update status / add note" aria-label="Update status or add a note for order {{ $order->reference }}"
                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-amber-50 dark:hover:bg-amber-900/20 hover:text-amber-600">
                <x-heroicon-o-pencil-square class="h-4 w-4"/>
            </button>
        </div>
    </div>
</div>
