{{-- Upload, match columns, check, import. Nothing is written before the final
     confirm. Same steps and look as Import Products. --}}
<x-filament-panels::page>
    @php
        $P = \App\Filament\Vendor\Pages\ImportCustomers::class;
        $steps = [
            $P::STEP_UPLOAD    => 'Upload',
            $P::STEP_MAP       => 'Match columns',
            $P::STEP_PREVIEW   => 'Check',
            $P::STEP_IMPORTING => 'Importing',
            $P::STEP_DONE      => 'Done',
        ];
        $order   = array_keys($steps);
        $current = array_search($step, $order, true);
    @endphp

    <ol class="flex flex-wrap items-center gap-2 text-sm">
        @foreach ($steps as $key => $label)
            @php $index = array_search($key, $order, true); @endphp
            <li class="flex items-center gap-2">
                <span @class([
                    'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-primary-600 text-white'                                     => $index === $current,
                    'bg-green-600 text-white'                                       => $index < $current,
                    'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400' => $index > $current,
                ])>{{ $index < $current ? '✓' : $index + 1 }}</span>
                <span @class([
                    'font-medium text-gray-900 dark:text-white' => $index === $current,
                    'text-gray-500 dark:text-gray-400'          => $index !== $current,
                ])>{{ $label }}</span>
                @unless ($loop->last)
                    <span class="mx-1 text-gray-300 dark:text-gray-600">&rarr;</span>
                @endunless
            </li>
        @endforeach
    </ol>

    {{-- ── Step 1: the file ─────────────────────────────────────────────── --}}
    @if ($step === $P::STEP_UPLOAD)
        <x-filament::section wire:key="customers-step-upload" heading="Choose your customer list">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                A CSV or Excel (.xlsx) export of your customers — from QuickBooks or any other software — or a
                spreadsheet you filled in yourself. The first row must be the column headings. Only a name is required.
            </p>

            <div class="mt-4">
                <input type="file" wire:model="upload" accept=".csv,.xlsx,text/csv"
                       class="block w-full text-sm text-gray-700 dark:text-gray-300
                              file:mr-4 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2
                              file:text-sm file:font-semibold file:text-white hover:file:bg-primary-500" />
                @error('upload')
                    <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
                <p wire:loading wire:target="upload" class="mt-2 text-sm text-gray-500">Reading the file…</p>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <x-filament::button wire:click="loadFile" wire:loading.attr="disabled" wire:target="upload,loadFile">
                    <span wire:loading.remove wire:target="loadFile">Continue</span>
                    <span wire:loading wire:target="loadFile">Reading…</span>
                </x-filament::button>
                <x-filament::button color="gray" outlined wire:click="downloadTemplate">
                    Download blank template (CSV)
                </x-filament::button>
            </div>

            <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700
                        dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                <p><strong>Re-importing is safe.</strong> Customers are matched by phone number (however it is written),
                    then by email, then — only when a row has neither — by name. A match is updated, never duplicated.</p>
                <p class="mt-2"><strong>Opening Balance</strong> is what a customer already owes you. It is recorded for
                    <em>new</em> customers only and appears under Customer Debts, ready to be paid off. An existing
                    customer's balance is never changed by an import.</p>
            </div>
        </x-filament::section>
    @endif

    {{-- ── Step 2: match columns ────────────────────────────────────────── --}}
    @if ($step === $P::STEP_MAP)
        <x-filament::section wire:key="customers-step-map" heading="Match your columns">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                The ones we recognised are filled in. Check them, and set any we missed. Columns left on
                "Ignore" are not imported.
            </p>

            <div class="mt-4 divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($headers as $index => $header)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $header }}</span>
                        <select wire:model.live="columnMap.{{ $index }}"
                                class="w-56 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                            <option value="">Ignore this column</option>
                            @foreach ($this->fieldOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <x-filament::button wire:click="buildPreview" wire:loading.attr="disabled" wire:target="buildPreview">
                    <span wire:loading.remove wire:target="buildPreview">Check the file</span>
                    <span wire:loading wire:target="buildPreview">Checking…</span>
                </x-filament::button>
                <x-filament::button color="gray" outlined wire:click="startOver">Start over</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- ── Step 3: what will happen ─────────────────────────────────────── --}}
    @if ($step === $P::STEP_PREVIEW)
        <x-filament::section wire:key="customers-step-preview" heading="Check before importing">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([['New', $summary['create'], 'text-green-600'], ['Updated', $summary['update'], 'text-blue-600'], ['Skipped', $summary['skip'], 'text-red-600'], ['Opening balances', $summary['balances'], 'text-amber-600']] as [$label, $count, $colour])
                    <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="text-2xl font-bold {{ $colour }}">{{ number_format($count) }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <tr><th class="py-2 pr-3">Row</th><th class="py-2 pr-3">Name</th><th class="py-2 pr-3">Phone</th><th class="py-2 pr-3 text-right">Opening balance</th><th class="py-2">What happens</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($previewRows as $row)
                            <tr class="align-top">
                                <td class="py-2 pr-3 text-gray-400">{{ $row['line'] }}</td>
                                <td class="py-2 pr-3 font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                                <td class="py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $row['phone'] }}</td>
                                <td class="py-2 pr-3 text-right text-gray-600 dark:text-gray-300">{{ $row['balance'] !== null ? '₦'.number_format((float) $row['balance'], 2) : '' }}</td>
                                <td class="py-2">
                                    <span @class([
                                        'font-semibold',
                                        'text-green-600' => $row['action'] === 'create',
                                        'text-blue-600'  => $row['action'] === 'update',
                                        'text-red-600'   => $row['action'] === 'skip',
                                    ])>{{ ['create' => 'New', 'update' => 'Update', 'skip' => 'Skip'][$row['action']] }}</span>
                                    @foreach ([...$row['errors'], ...$row['warnings']] as $note)
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($summary['total'] > count($previewRows))
                    <p class="mt-2 text-xs text-gray-500">Showing the first {{ count($previewRows) }} of {{ number_format($summary['total']) }} rows. The report after importing lists every problem.</p>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($summary['create'] + $summary['update'] > 0)
                    <x-filament::button wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport">
                        Import {{ number_format($summary['create'] + $summary['update']) }} customer(s)
                    </x-filament::button>
                @endif
                <x-filament::button color="gray" outlined wire:click="startOver">Start over</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- ── Step 4: writing, a batch per request ─────────────────────────── --}}
    @if ($step === $P::STEP_IMPORTING)
        <div wire:key="customers-step-importing" wire:poll.750ms="processBatch">
            <x-filament::section heading="Importing your customers">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ number_format($processed) }} of {{ number_format($toProcess) }} done.
                    Leave this page open until it finishes. If it stops, importing the same file again is safe.
                </p>
                <div class="mt-4 h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-600 transition-all duration-300" style="width: {{ $this->progressPercent() }}%"></div>
                </div>
                <p class="mt-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $this->progressPercent() }}%</p>
            </x-filament::section>
        </div>
    @endif

    {{-- ── Step 5: done ─────────────────────────────────────────────────── --}}
    @if ($step === $P::STEP_DONE)
        <x-filament::section wire:key="customers-step-done" heading="Import finished">
            <p class="text-lg font-semibold text-gray-900 dark:text-white">{{ $this->resultSummary() }}</p>

            @if ($messages !== [])
                <div class="mt-4 rounded-lg border border-gray-200 dark:border-white/10">
                    <p class="border-b border-gray-200 px-4 py-2 text-sm font-semibold text-gray-900 dark:border-white/10 dark:text-white">
                        {{ count($messages) }} note(s) — the first 50 are shown; the report has every one.
                    </p>
                    <ul class="max-h-72 divide-y divide-gray-100 overflow-y-auto text-sm dark:divide-white/5">
                        @foreach (collect($messages)->sortBy('line')->take(50) as $m)
                            <li @class(['px-4 py-2', 'text-red-700 dark:text-red-300' => $m['type'] === 'Not imported', 'text-amber-800 dark:text-amber-200' => $m['type'] === 'Warning'])>
                                <span class="font-mono">Row {{ $m['line'] }}</span> · {{ $m['name'] }} — {{ $m['message'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($messages !== [])
                    <x-filament::button color="warning" outlined wire:click="downloadReport">Download report (CSV)</x-filament::button>
                @endif
                <x-filament::button color="gray" outlined wire:click="startOver">Import another file</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
