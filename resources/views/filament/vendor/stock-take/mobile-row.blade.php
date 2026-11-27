@php
    // One row of a stock take list on a phone (a ViewColumn named mobile_card,
    // hidden from md up where the table takes over). The column's state says
    // what to show: ['title', 'lines' => [...], 'image' => url|null,
    // 'badge' => [label, colour]|null]. The row's own actions follow as
    // full-width buttons, prepared as the actions cell prepares them.
    $row    = $getState() ?? [];
    $record = $getRecord();

    $actions = collect($getTable()->getRecordActions())
        ->flatMap(fn ($action) => $action instanceof \Filament\Actions\ActionGroup ? array_values($action->getFlatActions()) : [$action])
        ->map(fn ($action) => $action->getClone()->group(null)->table($getTable())->record($record))
        ->reject(fn ($action) => $action->isHidden())
        ->map(fn ($action) => $action->button()->size('md')->extraAttributes(['class' => 'w-full justify-center'], merge: true));
@endphp

<div class="space-y-2.5 px-1 py-3">
    <div class="flex items-start gap-3">
        @if (! empty($row['image']))
            <img src="{{ $row['image'] }}" alt="" loading="lazy" class="h-14 w-14 shrink-0 rounded-lg bg-gray-100 object-cover dark:bg-gray-800" />
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-base font-semibold leading-snug text-gray-950 dark:text-white">{{ $row['title'] ?? '' }}</p>
            @foreach (array_filter($row['lines'] ?? []) as $line)
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $line }}</p>
            @endforeach
        </div>
        @if (! empty($row['badge']))
            <x-filament::badge :color="$row['badge'][1] ?? 'gray'">{{ $row['badge'][0] }}</x-filament::badge>
        @endif
    </div>

    @if ($actions->isNotEmpty())
        <div class="grid gap-2 {{ $actions->count() > 1 ? 'grid-cols-2' : 'grid-cols-1' }}">
            @foreach ($actions as $action)
                <div class="{{ $loop->last && $actions->count() % 2 === 1 && $actions->count() > 1 ? 'col-span-2' : '' }}">{{ $action }}</div>
            @endforeach
        </div>
    @endif
</div>
