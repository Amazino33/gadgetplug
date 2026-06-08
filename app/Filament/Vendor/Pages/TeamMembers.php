<?php

namespace App\Filament\Vendor\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Spatie\Permission\Models\Role;
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

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

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
            'owner'   => $vendor->user,
            'members' => $vendor->users()->withPivot('role')->get(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite Member')
                ->icon(Heroicon::OutlinedEnvelope)
                ->schema([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->label('Email Address'),

                    Select::make('role_id')
                        ->label('Role')
                        ->options(fn () => $this->vendorRoleOptions())
                        ->placeholder('Select a role…')
                        ->helperText('Create roles first via Settings → Roles.'),
                ])
                ->action(function (array $data): void {
                    $this->inviteMember($data['email'], $data['role_id'] ?? null);
                }),

            Action::make('setPosPin')
                ->label('Set POS PIN')
                ->icon(Heroicon::OutlinedFingerPrint)
                ->schema([
                    Select::make('user_id')
                        ->label('Staff Member')
                        ->options(fn () => filament()->getTenant()->users()->get()->pluck('name', 'id'))
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
                        Notification::make()->title('POS PIN set for ' . $user->name)->success()->send();
                    }
                }),

            Action::make('changeRole')
                ->label('Change Role')
                ->icon(Heroicon::OutlinedUserCircle)
                ->schema([
                    Select::make('user_id')
                        ->label('Member')
                        ->options(fn () => filament()->getTenant()->users()->get()->pluck('name', 'id'))
                        ->required(),

                    Select::make('role')
                        ->label('Store Role')
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
                    Notification::make()->title('Store role updated.')->success()->send();
                }),

            Action::make('assignRole')
                ->label('Assign Role')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    Select::make('user_id')
                        ->label('Member')
                        ->options(fn () => filament()->getTenant()->users()->get()->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (callable $set, $state) {
                            if (!$state) return;
                            $vendor = filament()->getTenant();
                            setPermissionsTeamId($vendor->id);
                            $user = User::find($state);
                            if (!$user) return;
                            $set('role_id', $user->roles->first()?->id);
                        }),

                    Select::make('role_id')
                        ->label('Role')
                        ->options(fn () => $this->vendorRoleOptions())
                        ->placeholder('Select a role…')
                        ->helperText('Create and configure roles via Settings → Roles.'),
                ])
                ->action(function (array $data): void {
                    $this->assignMemberRole($data['user_id'], $data['role_id'] ?? null);
                }),
        ];
    }

    private function vendorRoleOptions(): array
    {
        $vendorId = filament()->getTenant()->id;

        return Role::where('team_id', $vendorId)
            ->pluck('name', 'id')
            ->map(fn ($name) => Str::headline($name))
            ->toArray();
    }

    protected function inviteMember(string $email, ?int $roleId): void
    {
        $vendor = filament()->getTenant();
        $user   = User::where('email', $email)->first();

        if ($user) {
            if (!$vendor->users()->where('user_id', $user->id)->exists()) {
                $vendor->users()->attach($user->id, ['role' => 'member']);
            }

            if ($roleId) {
                setPermissionsTeamId($vendor->id);
                $user->syncRoles([$roleId]);
            }

            activity()->causedBy(auth()->user())
                ->withProperties(['email' => $email])
                ->tap(fn ($a) => $a->vendor_id = $vendor->id)
                ->log("Added {$user->name} to the team");

            Notification::make()->title('Member added successfully')->success()->send();
        } else {
            $token = Str::random(32);

            cache()->put("vendor_invite_{$token}", [
                'vendor_id' => $vendor->id,
                'email'     => $email,
                'role_id'   => $roleId,
            ], now()->addDays(7));

            Mail::to($email)->send(new \App\Mail\VendorInviteMail($vendor, $token, $email));

            activity()->causedBy(auth()->user())
                ->withProperties(['email' => $email])
                ->tap(fn ($a) => $a->vendor_id = $vendor->id)
                ->log("Invited {$email} to the team");

            Notification::make()->title('Invite sent to ' . $email)->success()->send();
        }
    }

    protected function assignMemberRole(int $userId, ?int $roleId): void
    {
        $vendor = filament()->getTenant();
        $member = User::find($userId);
        if (!$member) return;

        setPermissionsTeamId($vendor->id);
        $member->unsetRelation('roles');

        if ($roleId) {
            $member->syncRoles([$roleId]);
        } else {
            $member->syncRoles([]);
        }

        $roleName = $roleId ? \Spatie\Permission\Models\Role::find($roleId)?->name : 'none';
        activity()->causedBy(auth()->user())
            ->performedOn($member)
            ->withProperties(['role' => $roleName])
            ->tap(fn ($a) => $a->vendor_id = $vendor->id)
            ->log("Assigned role \"{$roleName}\" to {$member->name}");

        Notification::make()->title('Role assigned to ' . $member->name)->success()->send();
        $this->dispatch('$refresh');
    }

    public function removeMember(int $userId): void
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        if (!$vendor->isOwner($user) && !$user->isSuperAdmin()) {
            Notification::make()->title('Unauthorized')->danger()->send();
            return;
        }

        $member = User::find($userId);
        if ($member) {
            setPermissionsTeamId($vendor->id);
            $member->syncRoles([]);
            $member->syncPermissions([]);
            $vendor->users()->detach($userId);

            activity()->causedBy($user)
                ->withProperties(['removed_user' => $member->name])
                ->tap(fn ($a) => $a->vendor_id = $vendor->id)
                ->log("Removed {$member->name} from the team");

            Notification::make()->title('Member removed')->success()->send();
        }

        $this->dispatch('$refresh');
    }
}
