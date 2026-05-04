<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; gap: 24px;">

        @if($members->isEmpty())
            <div style="text-align: center; padding: 48px; color: #6b7280;">
                No team members yet. Use the Invite Member button to add someone.
            </div>
        @else
            <div style="background: white; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151;">Member</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151;">Email</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151;">Permissions</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($members as $member)
                            @php
                                setPermissionsTeamId(filament()->getTenant()->id);
                                $memberPermissions = $member->getAllPermissions();
                            @endphp
                            <tr style="border-bottom: 1px solid #f3f4f6;">
                                <td style="padding: 12px 16px; font-size: 14px; color: #111827; font-weight: 500;">
                                    {{ $member->name }}
                                </td>
                                <td style="padding: 12px 16px; font-size: 14px; color: #6b7280;">
                                    {{ $member->email }}
                                </td>
                                <td style="padding: 12px 16px;">
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        @foreach($memberPermissions as $permission)
                                            <span style="background: #dbeafe; color: #1d4ed8; font-size: 11px; padding: 2px 8px; border-radius: 9999px;">
                                                {{ str_replace('_', ' ', $permission->name) }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <button
                                        wire:click="removeMember({{ $member->id }})"
                                        wire:confirm="Are you sure you want to remove this member?"
                                        style="background: #fee2e2; color: #dc2626; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer;"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</x-filament-panels::page>