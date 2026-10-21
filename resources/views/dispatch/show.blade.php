<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Delivery {{ $order->reference }} — GadgetPlug</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
<main class="mx-auto max-w-md p-4">
    <h1 class="text-lg font-bold">GadgetPlug delivery</h1>
    <p class="text-sm text-gray-500">Order <span class="font-semibold text-gray-900">{{ $order->reference }}</span>
        @if ($order->logisticsCompany) · {{ $order->logisticsCompany->name }} @endif</p>

    @if ($stale)
        <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
            This link has been replaced. Use the most recent link GadgetPlug sent, or call GadgetPlug.
        </div>
    @else
        @if ($outcome)
            @php $result = $outcome['result']; @endphp
            <div @class([
                'mt-4 rounded-lg p-4 text-sm',
                'bg-green-50 text-green-800' => in_array($result, ['delivered', 'already_delivered'], true),
                'bg-red-50 text-red-800' => in_array($result, ['wrong_code', 'locked'], true),
                'bg-amber-50 text-amber-800' => $result === 'not_with_rider',
            ])>
                @switch($result)
                    @case('delivered') Delivered. Thank you! @break
                    @case('already_delivered') This order is already marked delivered. @break
                    @case('wrong_code') That code is not right. {{ $outcome['remaining'] }} {{ \Illuminate\Support\Str::plural('try', $outcome['remaining']) }} left — ask the customer to check their WhatsApp message from GadgetPlug. @break
                    @case('locked') Too many wrong codes. This delivery is locked — call GadgetPlug. @break
                    @default The store has not handed this order over yet.
                @endswitch
            </div>
        @endif

        <section class="mt-4 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-500">Pick up from</h2>
            @forelse ($pickups as $store)
                <p class="mt-1 text-sm"><span class="font-semibold">{{ $store->vendor?->name }} — {{ $store->name }}</span>
                    @if ($store->address)<br>{{ $store->address }}@endif
                    @if ($store->phone)<br><a class="text-green-700 underline" href="tel:{{ $store->phone }}">{{ $store->phone }}</a>@endif
                </p>
            @empty
                <p class="mt-1 text-sm">{{ $order->items->first()?->vendor?->name ?? 'The store' }} — call GadgetPlug for the address.</p>
            @endforelse
        </section>

        <section class="mt-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-500">Deliver to</h2>
            <p class="mt-1 text-sm"><span class="font-semibold">{{ $order->customer_name }}</span><br>
                <a class="text-green-700 underline" href="tel:{{ $order->customer_phone }}">{{ $order->customer_phone }}</a><br>
                {{ $order->shipping_address }}</p>
        </section>

        <section class="mt-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-500">Items</h2>
            <ul class="mt-1 space-y-1 text-sm">
                @foreach ($items as $item)
                    <li>{{ $item['quantity'] }} × {{ $item['name'] }}</li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm font-semibold">
                @if ($collect > 0)
                    Collect from the customer: ₦{{ number_format($collect, 2) }}
                @else
                    Already paid — collect nothing.
                @endif
            </p>
        </section>

        @if ($order->status === 'shipped' && ! $order->isDeliveryCodeLocked() && ($outcome['result'] ?? null) !== 'delivered')
            <form method="POST" action="{{ request()->fullUrl() }}" class="mt-4 rounded-lg bg-white p-4 shadow-sm">
                @csrf
                <label for="code" class="text-sm font-semibold">Customer's delivery code</label>
                <p class="text-xs text-gray-500">Ask the customer for the 4-digit code when they have their items.</p>
                <input id="code" name="code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="one-time-code" required
                       class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-lg tracking-widest">
                <button type="submit" class="mt-3 w-full rounded-lg bg-green-700 px-4 py-2 font-semibold text-white">Mark delivered</button>
            </form>
        @elseif ($order->status === 'delivered')
            <p class="mt-4 text-center text-sm font-semibold text-green-700">Delivered.</p>
        @elseif ($order->isDeliveryCodeLocked())
            <p class="mt-4 text-center text-sm font-semibold text-red-700">Locked after too many wrong codes — call GadgetPlug.</p>
        @elseif (in_array($order->status, ['confirmed', 'paid', 'packed'], true))
            <p class="mt-4 text-center text-sm text-gray-500">The store is preparing this order. Mark it delivered here once you have handed it over.</p>
        @endif
    @endif
</main>
</body>
</html>
