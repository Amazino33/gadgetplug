@php
    use App\Models\StockTakeResult;
    use App\Support\Quantity;

    // A count on a phone (ResultsRelationManager's mobile_card column, hidden
    // from md up where the table takes over): the three figures large, the
    // decisions as big buttons. Tapping the figures opens what happened.

    /** @var StockTakeResult $record */
    $record = $getRecord();
    $card   = $getLivewire()->cardData($record);
    $key    = (string) $record->getKey();
    $gap    = (float) $card['gap'];
    $tz     = 'Africa/Lagos';

    $status = match ($record->status) {
        StockTakeResult::STATUS_PENDING         => ['To decide', 'warning'],
        StockTakeResult::STATUS_APPROVED        => ['Approved', 'success'],
        StockTakeResult::STATUS_REJECTED        => ['Rejected', 'gray'],
        StockTakeResult::STATUS_SENT_TO_RECOUNT => ['Being recounted', 'info'],
        default                                 => [(string) $record->status, 'gray'],
    };

    $bad = collect([
        (float) $record->damaged_qty_base > 0 ? Quantity::format($record->damaged_qty_base) . ' damaged' : null,
        (float) $record->expired_qty_base > 0 ? Quantity::format($record->expired_qty_base) . ' expired' : null,
    ])->filter()->implode(' · ');

    // The row's own actions, prepared as the actions cell prepares them (same
    // visible()/authorize() checks), drawn as full-width buttons.
    $actions = collect($getTable()->getRecordActions())
        ->flatMap(fn ($action) => $action instanceof \Filament\Actions\ActionGroup ? array_values($action->getFlatActions()) : [$action])
        ->map(fn ($action) => $action->getClone()->group(null)->table($getTable())->record($record))
        ->reject(fn ($action) => $action->isHidden())
        ->map(fn ($action) => $action->button()->size('lg')->extraAttributes(['class' => 'w-full justify-center'], merge: true));
@endphp

<div class="space-y-3 px-1 py-3">
    <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-base font-semibold leading-snug text-gray-950 dark:text-white">{{ $record->product?->name }}</p>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                {{ collect([$card['aisles'] ?: null, $record->task?->assignee?->name, $record->counted_at?->timezone($tz)->format('D j M, g:ia')])->filter()->implode(' · ') }}
            </p>
        </div>
        <x-filament::badge :color="$status[1]">{{ $status[0] }}</x-filament::badge>
    </div>

    {{-- The three figures. Tap: what happened. --}}
    <button
        type="button"
        wire:click="mountTableAction('story', '{{ $key }}')"
        class="grid w-full grid-cols-3 overflow-hidden rounded-xl border border-gray-200 text-center dark:border-white/10"
        aria-label="See what happened to {{ $record->product?->name }}"
    >
        <span class="px-2 py-2.5">
            <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">Counted</span>
            <span class="block text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ Quantity::format($record->total_counted_base) }}</span>
        </span>
        <span class="border-x border-gray-200 px-2 py-2.5 dark:border-white/10">
            <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">Books said</span>
            <span class="block text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ Quantity::format($card['books']) }}</span>
        </span>
        <span class="px-2 py-2.5 {{ $gap < 0 ? 'bg-danger-50 dark:bg-danger-500/10' : ($gap > 0 ? 'bg-warning-50 dark:bg-warning-500/10' : 'bg-success-50 dark:bg-success-500/10') }}">
            <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">{{ $gap < 0 ? 'Missing' : ($gap > 0 ? 'Extra' : 'Gap') }}</span>
            <span class="block text-2xl font-bold tabular-nums {{ $gap < 0 ? 'text-danger-600 dark:text-danger-400' : ($gap > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400') }}">{{ $gap == 0 ? '✓' : Quantity::format(abs($gap)) }}</span>
        </span>
    </button>
    <p class="-mt-1 text-center text-xs font-medium text-primary-600 dark:text-primary-400">Tap the numbers to see what happened</p>

    @if ($card['breakdown'] || $bad || $card['value'] !== null)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ collect([
                $card['breakdown'],
                $bad ?: null,
                $card['value'] !== null && (float) $card['value'] != 0 ? (($card['value'] < 0 ? '−' : '+') . '₦' . number_format(abs($card['value']), 2)) : null,
            ])->filter()->implode(' · ') }}
        </p>
    @endif

    @if (count($card['flags']) > 0 || $card['pair'])
        <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($card['flags'] as $flag)
                <x-filament::badge size="sm" :color="match ($flag) {
                    'Recount needed', 'Late till sale — not yet corrected' => 'danger',
                    'Recount' => 'info',
                    'Not on aisle list', 'Not on shelf' => 'warning',
                    default => 'gray',
                }">{{ $flag }}</x-filament::badge>
            @endforeach
            @if ($card['pair'])
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $card['pair'] }}</span>
            @endif
        </div>
    @endif

    @if ($record->approver)
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $status[0] }} by {{ $record->approver->name }}</p>
    @endif

    @if ($actions->isNotEmpty())
        <div class="grid gap-2 {{ $actions->count() > 1 ? 'grid-cols-2' : 'grid-cols-1' }}">
            @foreach ($actions as $action)
                <div class="{{ $loop->first && $actions->count() === 3 ? 'col-span-2' : '' }}">{{ $action }}</div>
            @endforeach
        </div>
    @endif
</div>
