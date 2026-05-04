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

    // Only owner can see this page
    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        return $vendor && (
            $user->hasRole('super_admin') ||
            $vendor->isOwner($user)
        );
    }

    public function getViewData(): array
    {
        $vendor = filament()->getTenant();

        return [
            'members' => $vendor->users()->withPivot('role')->get(),
            'permissions' => Permission::where('guard_name', 'web')->get(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite Member')
                ->icon('heroicon-o-envelope')
                ->form([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->label('Email Address'),

                    Select::make('permissions')
                        ->multiple()
                        ->options(
                            Permission::where('guard_name', 'web')
                                ->pluck('name', 'name')
                                ->map(fn($name) => str_replace('_', ' ', ucfirst($name)))
                        )
                        ->label('Permissions')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->inviteMember($data['email'], $data['permissions']);
                }),
        ];
    }

    protected function inviteMember(string $email, array $permissions): void
    {
        $vendor = filament()->getTenant();
        $user = User::where('email', $email)->first();

        if ($user) {
            // Existing user — attach directly
if (!$vendor->users()->where('user_id', $user->id)->exists()) {
    $vendor->users()->attach($user->id, ['role' => 'member']); // member is now valid
}

            // Set team context then assign permissions
            setPermissionsTeamId($vendor->id);
            $user->givePermissionTo($permissions);

            Notification::make()
                ->title('Member added successfully')
                ->success()
                ->send();
        } else {
            // New user — store invite in session and send email
            $token = Str::random(32);

            cache()->put("vendor_invite_{$token}", [
                'vendor_id'   => $vendor->id,
                'email'       => $email,
                'permissions' => $permissions,
            ], now()->addDays(7));

            // Send invite email
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

    public function removeMember(int $userId): void
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (!$vendor->isOwner($user) && !$user->hasRole('super_admin')) {
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
                Permission::where('guard_name', 'web')->get()
            );
            $vendor->users()->detach($userId);

            Notification::make()
                ->title('Member removed')
                ->success()
                ->send();
        }

        $this->dispatch('$refresh');
    }
}