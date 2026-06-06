<x-filament-panels::page>

    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 overflow-hidden shadow-sm">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 dark:border-white/10 bg-gray-50 dark:bg-white/5">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Member</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Role</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Shield Role</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">

                {{-- Owner row — always first, cannot be removed --}}
                @if($owner)
                <tr class="bg-amber-50/50 dark:bg-amber-900/10 hover:bg-amber-50 dark:hover:bg-amber-900/20 transition-colors">
                    <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-[10px] font-bold shrink-0">
                            ★
                        </span>
                        {{ $owner->name }}
                    </td>
                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $owner->email }}</td>
                    <td class="px-4 py-3">
                        <x-filament::badge color="warning">Store Owner</x-filament::badge>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs text-gray-400 dark:text-gray-500">Full access</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs text-gray-300 dark:text-gray-600">—</span>
                    </td>
                </tr>
                @endif

                {{-- Team members --}}
                @forelse($members as $member)
                    @php
                        setPermissionsTeamId(filament()->getTenant()->id);
                        $shieldRole = $member->roles->first();
                        $storeRole  = $member->pivot->role ?? 'member';
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                            {{ $member->name }}
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                            {{ $member->email }}
                        </td>
                        <td class="px-4 py-3">
                            <x-filament::badge color="gray">
                                {{ ucwords(str_replace('_', ' ', $storeRole)) }}
                            </x-filament::badge>
                        </td>
                        <td class="px-4 py-3">
                            @if($shieldRole)
                                <x-filament::badge color="primary">
                                    {{ \Illuminate\Support\Str::headline($shieldRole->name) }}
                                </x-filament::badge>
                            @else
                                <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <button
                                wire:click="removeMember({{ $member->id }})"
                                wire:confirm="Remove {{ $member->name }} from this store?"
                                class="text-xs font-medium text-danger-600 dark:text-danger-400 hover:text-danger-700 dark:hover:text-danger-300 transition-colors"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                @empty
                    @if(!$owner)
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-gray-400 dark:text-gray-500 text-sm">
                            No team members yet. Use the Invite Member button above to add someone.
                        </td>
                    </tr>
                    @endif
                @endforelse

            </tbody>
        </table>
    </div>

</x-filament-panels::page>
