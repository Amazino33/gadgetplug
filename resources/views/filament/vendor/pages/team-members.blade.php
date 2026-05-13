<x-filament-panels::page>

    @if($members->isEmpty())
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-12 text-center shadow-sm">
            <x-filament::icon
                icon="heroicon-o-users"
                class="mx-auto h-10 w-10 mb-3 text-gray-300 dark:text-gray-600"
            />
            <p class="font-medium text-gray-600 dark:text-gray-300">No team members yet.</p>
            <p class="text-sm mt-1 text-gray-400 dark:text-gray-500">Use the Invite Member button above to add someone.</p>
        </div>
    @else
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 overflow-hidden shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-white/10 bg-gray-50 dark:bg-white/5">
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Member</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Role</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Permissions</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach($members as $member)
                        @php
                            setPermissionsTeamId(filament()->getTenant()->id);
                            $memberPermissions = $member->getAllPermissions();
                            $role = $member->pivot->role ?? 'member';
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                                {{ $member->name }}
                            </td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                {{ $member->email }}
                            </td>
                            <td class="px-4 py-3">
                                <x-filament::badge
                                    :color="$role === 'owner' ? 'warning' : 'gray'"
                                >
                                    {{ ucfirst($role) }}
                                </x-filament::badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @forelse($memberPermissions as $permission)
                                        <x-filament::badge color="info" size="sm">
                                            {{ str_replace('_', ' ', $permission->name) }}
                                        </x-filament::badge>
                                    @empty
                                        <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if($role !== 'owner')
                                    <button
                                        wire:click="removeMember({{ $member->id }})"
                                        wire:confirm="Remove {{ $member->name }} from this store?"
                                        class="text-xs font-medium text-danger-600 dark:text-danger-400 hover:text-danger-700 dark:hover:text-danger-300 transition-colors"
                                    >
                                        Remove
                                    </button>
                                @else
                                    <span class="text-xs text-gray-300 dark:text-gray-600">Owner</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</x-filament-panels::page>
