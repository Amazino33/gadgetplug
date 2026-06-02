<?php

namespace App\Filament\Vendor\Pages;

use App\Models\User;
use App\Models\Vendor;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TeamMembers extends Page
{
    protected static ?string $navigationLabel = 'Team Members';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static ?string $title = 'Team Members';
    protected string $view = 'filament.vendor.pages.team-members';

    public ?string $inviteEmail = '';
    public array $invitePermissions = [];

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user)
        );
    }

    public function getViewData(): array
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor->id);

        return [
            'owner'       => $vendor->user,
            'members'     => $vendor->users()->withPivot('role')->get(),
            'permissions' => Permission::where('guard_name', 'web')
                ->where('name', 'not like', '%:%')
                ->get(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite Member')
                ->icon(Heroicon::OutlinedEnvelope)
                ->form([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->label('Email Address'),

                    Select::make('permissions')
                        ->multiple()
                        ->options(
                            Permission::where('guard_name', 'web')
                                ->where('name', 'not like', '%:%')
                                ->pluck('name', 'name')
                                ->map(fn($name) => str_replace('_', ' ', ucfirst($name)))
                        )
                        ->label('Permissions')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->inviteMember($data['email'], $data['permissions']);
                }),

            Action::make('setPosPin')
                ->label('Set POS PIN')
                ->icon(Heroicon::OutlinedFingerPrint)
                ->form([
                    Select::make('user_id')
                        ->label('Staff Member')
                        ->options(
                            filament()->getTenant()
                                ->users()
                                ->get()
                                ->pluck('name', 'id')
                        )
                        ->required(),

                    TextInput::make('pin')
                        ->label('New PIN (4–6 digits)')
                        ->password()
                        ->numeric()
                        ->minLength(4)
                        ->maxLength(6)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $user = User::find($data['user_id']);
                    if ($user) {
                        $user->update(['pos_pin' => Hash::make($data['pin'])]);
                        Notification::make()
                            ->title('POS PIN set for ' . $user->name)
                            ->success()
                            ->send();
                    }
                }),

            Action::make('changeRole')
                ->label('Change Role')
                ->icon(Heroicon::OutlinedUserCircle)
                ->form([
                    Select::make('user_id')
                        ->label('Member')
                        ->options(
                            filament()->getTenant()
                                ->users()
                                ->get()
                                ->pluck('name', 'id')
                        )
                        ->required(),

                    Select::make('role')
                        ->label('Role')
                        ->options([
                            'member'            => 'Member',
                            'product_manager'   => 'Product Manager',
                            'order_manager'     => 'Order Manager',
                            'inventory_manager' => 'Inventory Manager',
                            'storekeeper'       => 'Storekeeper',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $vendor = filament()->getTenant();
                    $vendor->users()->updateExistingPivot($data['user_id'], ['role' => $data['role']]);
                    Notification::make()->title('Role updated.')->success()->send();
                }),

            // ← new: edit permissions action
            Action::make('editPermissions')
                ->label('Edit Permissions')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->form([
                    Select::make('user_id')
                        ->label('Member')
                        ->options(
                            filament()->getTenant()
                                ->users()
                                ->get()
                                ->pluck('name', 'id')
                        )
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (callable $set, $state) {
                            if (!$state) return;

                            $vendor = filament()->getTenant();
                            setPermissionsTeamId($vendor->id);

                            $user = \App\Models\User::find($state);
                            if (!$user) return;

                            $set('permissions', $user->getAllPermissions()->pluck('name')->toArray());
                        }),

                    Select::make('permissions')
                        ->label('Permissions')
                        ->multiple()
                        ->options(
                            Permission::where('guard_name', 'web')
                                ->where('name', 'not like', '%:%')
                                ->pluck('name', 'name')
                                ->map(fn($name) => str_replace('_', ' ', ucfirst($name)))
                        )
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->updateMemberPermissions($data['user_id'], $data['permissions']);
                }),
        ];
    }

    protected function inviteMember(string $email, array $permissions): void
    {
        $vendor = filament()->getTenant();
        $user = User::where('email', $email)->first();

        if ($user) {
            if (!$vendor->users()->where('user_id', $user->id)->exists()) {
                $vendor->users()->attach($user->id, ['role' => 'member']);
            }

            setPermissionsTeamId($vendor->id);
            $user->givePermissionTo($permissions);

            Notification::make()
                ->title('Member added successfully')
                ->success()
                ->send();
        } else {
            $token = Str::random(32);

            cache()->put("vendor_invite_{$token}", [
                'vendor_id'   => $vendor->id,
                'email'       => $email,
                'permissions' => $permissions,
            ], now()->addDays(7));

            Mail::to($email)->send(new \App\Mail\VendorInviteMail(
                $vendor,
                $token,
                $email
            ));

            Notification::make()
                ->title('Invite sent to ' . $email)
                ->success()
                ->send();
        }
    }

    // ← new: update permissions for existing member
    protected function updateMemberPermissions(int $userId, array $permissions): void
    {
        $vendor = filament()->getTenant();
        $member = User::find($userId);

        if (!$member) return;

        setPermissionsTeamId($vendor->id);

        // Force fresh permission fetch after setting team context
        $member->unsetRelation('permissions');
        $member->unsetRelation('roles');

        // Sync instead of revoke+give — cleaner and atomic
        $member->syncPermissions($permissions);

        Notification::make()
            ->title('Permissions updated for ' . $member->name)
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }

    public function removeMember(int $userId): void
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (!$vendor->isOwner($user) && !$user->isSuperAdmin()) {
            Notification::make()
                ->title('Unauthorized')
                ->danger()
                ->send();
            return;
        }

        $member = User::find($userId);
        if ($member) {
            setPermissionsTeamId($vendor->id);
            $member->revokePermissionTo(
                Permission::where('guard_name', 'web')
                    ->where('name', 'not like', '%:%')
                    ->get()
            );
            $vendor->users()->detach($userId);

            Notification::make()
                ->title('Member removed')
                ->success()
                ->send();
        }

        $this->dispatch('$refresh');
    }

    public function editMemberPermissions(int $userId): void
    {
        $this->dispatch('open-edit-permissions', userId: $userId);
    }
}