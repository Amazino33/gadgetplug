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

    $statusOptions = $this->statusOptionsFor($order);

    $viewUrl = \App\Filament\Vendor\Resources\Orders\OrderResource::getUrl('view', ['record' => $order], tenant: filament()->getTenant());
@endphp

<tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
    <td class="py-3 px-4">
        <a href="{{ $viewUrl }}" class="group">
            <p class="font-montserrat text-sm font-bold text-gray-900 dark:text-white group-hover:underline">{{ $order->reference }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $order->local_government ?? '—' }}</p>
        </a>
    </td>
    <td class="py-3 px-4">
        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $order->customer_name }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $order->customer_phone }}</p>
    </td>
    <td class="py-3 px-4">
        <span class="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold {{ $paymentClasses }}">{{ $paymentLabel }}</span>
    </td>
    <td class="py-3 px-4 text-center">
        <span class="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold {{ $statusClasses }}">{{ $statusLabel }}</span>
    </td>
    <td class="whitespace-nowrap py-3 px-4 text-right">
        <p class="font-montserrat text-sm font-bold text-gray-900 dark:text-white">₦{{ number_format((float) $order->total_amount, 2) }}</p>
    </td>
    <td class="whitespace-nowrap py-3 px-4 text-xs text-gray-500 dark:text-gray-400">
        {{ $order->created_at->format('d M Y, g:ia') }}
    </td>
    <td class="py-3 px-4">
        <div class="flex items-center justify-end gap-1">
            <a href="{{ $viewUrl }}" title="View" aria-label="View order {{ $order->reference }}"
                class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 dark:hover:bg-white/10 hover:text-gray-700 dark:hover:text-white">
                <x-heroicon-o-eye class="h-4 w-4"/>
            </a>
            <a href="tel:{{ $order->customer_phone }}"
                title="Call" aria-label="Call {{ $order->customer_name }}"
                class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-600">
                <x-heroicon-o-phone class="h-4 w-4"/>
            </a>
            <button type="button"
                x-data
                x-on:click="window.dispatchEvent(new CustomEvent('open-send-message', { detail: {
                    id: {{ $order->id }},
                    customer: @js($order->customer_name),
                    phone: @js($order->customer_phone),
                    templates: @js($this->messageTemplatesFor($order)),
                } }))"
                title="Send message" aria-label="Send a message to {{ $order->customer_name }}"
                class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-600">
                <x-heroicon-o-chat-bubble-oval-left class="h-4 w-4"/>
            </button>
            <button type="button"
                x-data
                x-on:click="window.dispatchEvent(new CustomEvent('open-update-status', { detail: { id: {{ $order->id }}, options: @js($statusOptions), needsChannel: @js($this->requiresPaymentChannel($order)) } }))"
                title="Update status / add note" aria-label="Update status or add a note for order {{ $order->reference }}"
                class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-amber-50 dark:hover:bg-amber-900/20 hover:text-amber-600">
                <x-heroicon-o-pencil-square class="h-4 w-4"/>
            </button>
        </div>
    </td>
</tr>
