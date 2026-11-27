<x-filament-widgets::widget>
    @if (count($branch) > 0)
        <x-filament::section heading="Counter accuracy" description="How often each person's counts held when someone else counted the same product again (a recount or a spot-check). Everything on the shelf counts: good, damaged and expired.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-3">Counter</th>
                            <th class="py-2 pe-3 text-end">This stock take</th>
                            <th class="py-2 pe-3 text-end">All stock takes here</th>
                            <th class="py-2 text-end">Off by, in all</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($branch as $row)
                            @php
                                $now = collect($here)->firstWhere('user_id', $row['user_id']);
                                $tone = $row['percent'] >= 95 ? 'text-success-600 dark:text-success-400' : ($row['percent'] >= 80 ? 'text-warning-600 dark:text-warning-400' : 'text-danger-600 dark:text-danger-400');
                            @endphp
                            <tr>
                                <td class="py-2 pe-3 font-semibold text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                                <td class="py-2 pe-3 text-end tabular-nums text-gray-700 dark:text-gray-300">
                                    @if ($now)
                                        {{ $now['percent'] }}% <span class="text-xs text-gray-500">({{ $now['held'] }} of {{ $now['checked'] }})</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pe-3 text-end tabular-nums font-bold {{ $tone }}">
                                    {{ $row['percent'] }}% <span class="text-xs font-normal text-gray-500">({{ $row['held'] }} of {{ $row['checked'] }})</span>
                                </td>
                                <td class="py-2 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $row['off_text'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
