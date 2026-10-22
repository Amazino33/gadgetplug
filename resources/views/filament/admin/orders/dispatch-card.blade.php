@php
    use App\Filament\Resources\Orders\Pages\ViewOrder;
    use App\Services\Orders\OrderLocation;
    use App\Support\Naira;
    use App\Support\OrderBadges;

    // The dispatch board on a phone: one card per order (OrderResource's
    // mobile_card column, hidden from md up where the table takes over).

    /** @var \App\Models\Order $record */
    $record = $getRecord();
    $collect = $record->amountToCollect();
    $paymentState = OrderBadges::paymentState($record);

    // Its next step, if it has one: the first of these the row offers. The
    // same DispatchActions the row's menu holds, bound to this table and
    // record — same visibility and authorization; tapping it mounts the
    // board's own action of that name.
    $mainAction = collect([
        \App\Filament\Resources\Orders\DispatchActions::assign(),
        \App\Filament\Resources\Orders\DispatchActions::recordPaymentReceived(),
        \App\Filament\Resources\Orders\DispatchActions::markDelivered(),
    ])
        ->map(fn ($action) => $action->table($getTable())->record($record))
        ->first(fn ($action) => ! $action->isHidden());
@endphp

<div class="gp-card">
    <div class="gp-dispatch-card-head">
        <a href="{{ ViewOrder::getUrl(['record' => $record]) }}" class="gp-card-title">#{{ $record->reference }}</a>
        <x-filament::badge :color="OrderBadges::statusColor($record->status)">
            {{ OrderBadges::statusLabel($record->status) }}
        </x-filament::badge>
    </div>

    <div class="gp-card-body">
        <p class="gp-card-meta">
            <span>{{ $record->customer_name }}</span>
            <span>{{ $record->checkout?->lga?->name ?? $record->local_government ?? '—' }}</span>
        </p>

        <p class="gp-card-meta">
            <span>{{ $record->items->first()?->vendor?->name ?? '—' }}</span>
            <span class="gp-card-price">{{ $collect > 0 ? 'Collect '.Naira::format($collect) : 'Nothing to collect' }}</span>
        </p>

        <div class="gp-card-badges">
            <x-filament::badge :color="OrderBadges::paymentStateColor($paymentState)" size="sm">{{ $paymentState }}</x-filament::badge>

            @if ($record->logistics_company_id)
                <x-filament::badge color="gray" size="sm">{{ $record->logisticsCompany?->name }}</x-filament::badge>
            @endif

            @if (app(OrderLocation::class)->mismatched($record))
                <x-filament::badge color="warning" size="sm" icon="heroicon-m-exclamation-triangle">Location doesn't match LGA</x-filament::badge>
            @endif
        </div>
    </div>

    <div class="gp-card-actions">
        @if ($mainAction)
            {{ $mainAction->button()->size('lg')->extraAttributes(['class' => 'gp-card-action'], merge: true) }}
        @endif

        <x-filament::button
            tag="a"
            :href="ViewOrder::getUrl(['record' => $record])"
            color="gray"
            size="lg"
            icon="heroicon-m-eye"
            class="gp-card-action"
        >
            Open order
        </x-filament::button>
    </div>
</div>
