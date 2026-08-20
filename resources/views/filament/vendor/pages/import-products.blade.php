{{-- Upload, map, check, confirm. Nothing touches the catalogue before the
     final confirm, and the step markers say so at every stage. --}}
<x-filament-panels::page>

    @php
        $steps = [
            \App\Filament\Vendor\Pages\ImportProducts::STEP_UPLOAD    => 'Upload',
            \App\Filament\Vendor\Pages\ImportProducts::STEP_MAP       => 'Match columns',
            \App\Filament\Vendor\Pages\ImportProducts::STEP_PREVIEW   => 'Check',
            \App\Filament\Vendor\Pages\ImportProducts::STEP_IMPORTING => 'Importing',
            \App\Filament\Vendor\Pages\ImportProducts::STEP_DONE      => 'Done',
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
                    'bg-primary-600 text-white'                                  => $index === $current,
                    'bg-green-600 text-white'                                    => $index < $current,
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

    {{-- A failure returns the vendor to the step they were already on, so
         without this the screen looks identical to nothing having happened.
         Shown on the page, not only as a toast, because a toast in the corner
         is exactly what gets missed. --}}
    @if ($fatalError)
        <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 dark:border-red-500/30 dark:bg-red-500/10">
            <p class="text-sm font-semibold text-red-900 dark:text-red-200">The import stopped and nothing was changed.</p>
            <p class="mt-1 font-mono text-xs break-all text-red-800 dark:text-red-300">{{ $fatalError }}</p>
            <p class="mt-2 text-xs text-red-800 dark:text-red-300">
                Send this line on if it is not obvious — it names the exact file and line that failed.
            </p>
        </div>
    @endif

    {{-- ── Step 1: upload ──────────────────────────────────────────────── --}}
    @if ($step === \App\Filament\Vendor\Pages\ImportProducts::STEP_UPLOAD)
        <x-filament::section heading="Choose your file">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                A CSV or Excel (.xlsx) export from your current POS, or a spreadsheet you filled in
                yourself. The first row must be the column headings.
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

                <x-filament::button color="gray" outlined wire:click="downloadTemplate('csv')">
                    Download blank template (CSV)
                </x-filament::button>

                <x-filament::button color="gray" outlined wire:click="downloadTemplate('xlsx')">
                    Excel
                </x-filament::button>
            </div>

            <div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900
                        dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <strong>Stock quantities are not imported.</strong>
                A file cannot say which branch its numbers belong to, and every stock movement here is
                recorded against a branch so it can be traced later. Import your catalogue first, then set
                opening stock with a count or a procurement — which leaves a record, as it should.
            </div>
        </x-filament::section>
    @endif

    {{-- ── Step 2: mapping ─────────────────────────────────────────────── --}}
    @if ($step === \App\Filament\Vendor\Pages\ImportProducts::STEP_MAP)
        <x-filament::section heading="Match your columns">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ count($headers) }} column(s) found in <strong>{{ $originalName }}</strong>.
                We have guessed the obvious ones — check them, and set anything left to "Ignore".
            </p>

            @if ($this->savedTemplates()->isNotEmpty())
                <div class="mt-4 flex flex-wrap items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-400">Use a saved mapping</span>
                        <select wire:model="templateId"
                                class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                            <option value="">Choose…</option>
                            @foreach ($this->savedTemplates() as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-filament::button size="sm" color="gray" wire:click="applyTemplate">Apply</x-filament::button>
                </div>
            @endif

            <div class="mt-5 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">Column in your file</th>
                            <th class="px-3 py-2 text-left font-medium">Import as</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($headers as $index => $header)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">{{ $header }}</td>
                                <td class="px-3 py-2">
                                    <select wire:model.live="columnMap.{{ $index }}"
                                            class="w-full max-w-xs rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                        <option value="">Ignore this column</option>
                                        @foreach ($this->fieldOptions() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if ($hint = $this->hintFor($columnMap[$index] ?? null))
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->unmappedFields()->isNotEmpty())
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Not filled by this file:
                    {{ $this->unmappedFields()->map(fn ($f) => $f->label())->implode(', ') }}.
                    Existing products keep whatever they already have in those fields.
                </p>
            @endif

            <div class="mt-5 flex flex-wrap items-end gap-3">
                <x-filament::button wire:click="buildPreview" wire:loading.attr="disabled" wire:target="buildPreview">
                    <span wire:loading.remove wire:target="buildPreview">Check the file</span>
                    <span wire:loading wire:target="buildPreview">Checking…</span>
                </x-filament::button>

                <label class="text-sm">
                    <span class="mb-1 block text-gray-600 dark:text-gray-400">Save this mapping for next time</span>
                    <input type="text" wire:model="newTemplateName" placeholder="e.g. Aronium export"
                           class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" />
                </label>
                <x-filament::button size="sm" color="gray" outlined wire:click="saveTemplate">Save mapping</x-filament::button>

                <x-filament::button color="gray" outlined wire:click="startOver">Start over</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- ── Step 3: preview ─────────────────────────────────────────────── --}}
    @if ($step === \App\Filament\Vendor\Pages\ImportProducts::STEP_PREVIEW)
        <x-filament::section heading="What this will do">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    ['New products', $summary['create'] ?? 0, 'text-green-600 dark:text-green-400'],
                    ['Updated', $summary['update'] ?? 0, 'text-amber-600 dark:text-amber-400'],
                    ['Skipped', $summary['skip'] ?? 0, 'text-red-600 dark:text-red-400'],
                    ['Rows in file', $summary['total'] ?? 0, 'text-gray-900 dark:text-white'],
                ] as [$label, $value, $colour])
                    <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-bold {{ $colour }}">{{ number_format($value) }}</div>
                    </div>
                @endforeach
            </div>

            @if ($problems !== [])
                <div class="mt-5 rounded-lg border border-red-300 bg-red-50 px-4 py-3 dark:border-red-500/30 dark:bg-red-500/10">
                    <p class="text-sm font-semibold text-red-900 dark:text-red-200">
                        {{ count($problems) }} row(s) cannot be imported
                    </p>
                    <p class="mt-1 text-xs text-red-800 dark:text-red-300">
                        Fix them in your file and upload it again, or continue and import only the good rows.
                    </p>
                    <ul class="mt-3 max-h-56 space-y-1 overflow-y-auto text-xs text-red-900 dark:text-red-200">
                        @foreach ($problems as $problem)
                            <li>
                                <strong>Line {{ $problem['line'] }}</strong>
                                ({{ $problem['name'] }}) — {{ implode(' ', $problem['errors']) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="mt-5 text-sm font-medium text-gray-900 dark:text-white">First {{ count($previewRows) }} rows</p>

            <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">Line</th>
                            <th class="px-3 py-2 text-left font-medium">Name</th>
                            <th class="px-3 py-2 text-left font-medium">SKU</th>
                            <th class="px-3 py-2 text-right font-medium">Cost</th>
                            <th class="px-3 py-2 text-right font-medium">Price</th>
                            <th class="px-3 py-2 text-left font-medium">Will do</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($previewRows as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2 text-gray-500">{{ $row['line'] }}</td>
                                <td class="px-3 py-2">
                                    {{ $row['name'] }}
                                    @foreach ($row['errors'] as $error)
                                        <div class="text-xs text-red-600 dark:text-red-400">{{ $error }}</div>
                                    @endforeach
                                    @foreach ($row['warnings'] as $warning)
                                        <div class="text-xs text-amber-600 dark:text-amber-400">{{ $warning }}</div>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $row['sku'] ?: '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['cost'] === null ? '—' : number_format((float) $row['cost'], 2) }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['price'] === null ? '—' : number_format((float) $row['price'], 2) }}</td>
                                <td class="px-3 py-2">
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300' => $row['action'] === 'create',
                                        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' => $row['action'] === 'update',
                                        'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300'         => $row['action'] === 'skip',
                                    ])>
                                        {{ ['create' => 'Create', 'update' => 'Update', 'skip' => 'Skip'][$row['action']] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (($summary['update'] ?? 0) > 0)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    A copy of your current catalogue is saved before anything changes, so this can be undone.
                </p>
            @endif

            {{-- Repeated here, next to the button, because a failure returns to
                 this screen and a panel at the top of a long preview table is
                 off-screen from where the click happened. --}}
            @if ($fatalError)
                <div class="mt-5 rounded-lg border border-red-300 bg-red-50 px-4 py-3 dark:border-red-500/30 dark:bg-red-500/10">
                    <p class="text-sm font-semibold text-red-900 dark:text-red-200">The import stopped and nothing was changed.</p>
                    <p class="mt-1 font-mono text-xs break-all text-red-800 dark:text-red-300">{{ $fatalError }}</p>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                <x-filament::button color="success" wire:click="commit" wire:loading.attr="disabled" wire:target="commit">
                    <span wire:loading.remove wire:target="commit">
                        Import {{ number_format(($summary['create'] ?? 0) + ($summary['update'] ?? 0)) }} product(s)
                    </span>
                    <span wire:loading wire:target="commit">Starting…</span>
                </x-filament::button>

                <x-filament::button color="gray" outlined wire:click="$set('step', 'map')">Back to columns</x-filament::button>
                <x-filament::button color="gray" outlined wire:click="startOver">Start over</x-filament::button>
            </div>

            {{-- Answers the one question a silent failure raises and nothing
                 else on this page can: did the run reach the database at all? --}}
            @php $last = $this->lastLog(); @endphp

            @if ($trace)
                <p class="mt-4 font-mono text-xs text-gray-500 dark:text-gray-400">Last click: {{ $trace }}</p>
            @endif

            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                @if ($last)
                    Last import recorded: <strong>#{{ $last->id }}</strong> —
                    {{ $last->status }}, {{ $last->summary() }},
                    {{ $last->created_at->diffForHumans() }} ({{ $last->file_name }}).
                @else
                    No import has ever been recorded for this store. If you press Import and this line
                    still says that, the run is not reaching the database and the message above will say why.
                @endif
            </p>
        </x-filament::section>
    @endif

    {{-- ── Step 4: importing ───────────────────────────────────────────── --}}
    @if ($step === \App\Filament\Vendor\Pages\ImportProducts::STEP_IMPORTING)
        {{-- Polled rather than done in one request: a large catalogue is several
             thousand queries, and shared hosting kills a request long before
             that finishes — silently, which is why the button appeared dead. --}}
        <div wire:poll.750ms="processBatch">
            <x-filament::section heading="Importing your products">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ number_format($processed) }} of {{ number_format($toProcess) }} done.
                    Leave this page open until it finishes — closing it stops the import partway.
                </p>

                <div class="mt-4 h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-600 transition-all duration-300"
                         style="width: {{ $this->progressPercent() }}%"></div>
                </div>

                <p class="mt-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">
                    {{ $this->progressPercent() }}%
                </p>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Products already written are safe. If this stops, importing the same file again
                    updates what came through rather than duplicating it.
                </p>
            </x-filament::section>
        </div>
    @endif

    {{-- ── Step 5: results ─────────────────────────────────────────────── --}}
    @if ($step === \App\Filament\Vendor\Pages\ImportProducts::STEP_DONE)
        @php $log = $this->result(); @endphp

        <x-filament::section heading="Import finished">
            @if ($log)
                <p class="text-lg font-semibold text-gray-900 dark:text-white">{{ $log->summary() }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    From <strong>{{ $log->file_name }}</strong> on {{ $log->created_at->format('d M Y, g:ia') }}.
                </p>

                @if ($log->skipped_count > 0)
                    <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900
                                dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        {{ $log->skipped_count }} row(s) were skipped and are listed against this import for reference.
                        Fix them in your file and import it again — the products that did come through will be updated,
                        not duplicated.
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap gap-3">
                    <x-filament::button
                        tag="a"
                        href="{{ \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('index') }}">
                        View products
                    </x-filament::button>

                    @if ($log->hasSnapshot())
                        <x-filament::button color="gray" outlined wire:click="downloadSnapshot">
                            Download the pre-import copy
                        </x-filament::button>
                    @endif

                    <x-filament::button color="gray" outlined wire:click="startOver">Import another file</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endif

</x-filament-panels::page>
