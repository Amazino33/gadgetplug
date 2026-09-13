{{-- The rows an import refused, kept so a vendor can fix their file rather
     than guess at what a count of "17 skipped" was made of.

     Capped at 200 when written, so a pathological file cannot make this modal
     unrenderable; the count above is always the true one. --}}
@php
    /** @var \App\Models\ImportLog $log */
    $problems = collect($log->errors ?? [])->filter(fn ($row) => filled($row['errors'] ?? null));
@endphp

<div class="space-y-3 p-1">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ $problems->count() }} {{ $problems->count() === 1 ? 'row was' : 'rows were' }} not imported.
        Nothing about them reached the catalogue — fix the file and import it again.
    </p>

    <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead class="sticky top-0 bg-gray-50 text-left dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">Line</th>
                    <th class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">Name</th>
                    <th class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">Why</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($problems as $row)
                    <tr>
                        {{-- Null for the whole-run failure entry the importer
                             appends on a rollback, which belongs in this list
                             but has no line of its own. --}}
                        <td class="whitespace-nowrap px-3 py-2 text-gray-500 dark:text-gray-400">
                            {{ $row['line'] ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-gray-900 dark:text-white">
                            {{ ($row['name'] ?? null) ?: '—' }}
                        </td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                            {{ implode(' ', (array) ($row['errors'] ?? [])) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
