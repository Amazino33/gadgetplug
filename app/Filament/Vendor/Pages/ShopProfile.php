<?php

namespace App\Filament\Vendor\Pages;

use App\Models\Vendor;
use App\Services\FreePlan\FreeShopApplications;
use App\Services\FreePlan\ShopImageStore;
use App\Support\NigerianStates;
use App\Support\WhatsAppNumber;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Facades\Filament;
use Illuminate\Validation\Rules\Password;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * A free shop's own profile: logo, description, category, location and
 * WhatsApp number. The free plan's counterpart to StoreProfile, which is all
 * till, VAT and payout settings a free shop has no use for.
 *
 * The shop link is shown but never edited here. Before approval it changes
 * only through the application form; after approval it is what the shop has
 * already handed to its customers.
 */
class ShopProfile extends Page
{
    use InteractsWithForms;

    protected static ?string $slug = 'shop-profile';

    protected string $view = 'filament.vendor.pages.store-profile';

    protected static ?string $navigationLabel = 'Shop Profile';

    protected static ?string $title = 'Shop Profile';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $user && $vendor instanceof Vendor && $vendor->isFreePlan()
            && ($user->isSuperAdmin() || $vendor->isOwner($user));
    }

    // The free sidebar (FreeShopNavigation) links here; the panel's normal
    // menu never should, since paid vendors have StoreProfile.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $vendor = filament()->getTenant();

        $email = $vendor->user?->email;

        $this->form->fill([
            // The placeholder is never shown; the field reads as empty.
            'email'       => FreeShopApplications::isPlaceholderEmail($email) ? null : $email,
            'logo'        => $vendor->logo,
            'description' => $vendor->description,
            'category'    => $vendor->category,
            'state'       => $vendor->state,
            'city'        => $vendor->city,
            'whatsapp'    => WhatsAppNumber::local($vendor->whatsapp),
        ]);
    }

    public function form(Schema $form): Schema
    {
        $vendor = filament()->getTenant();

        return $form
            ->schema([
                Section::make($vendor->name)
                    ->schema([
                        Placeholder::make('shop_link')
                            ->label('Shop link')
                            ->content($vendor->shopLinkLabel().($vendor->hasStatus(Vendor::STATUS_APPROVED) ? '' : ' — not live yet'))
                            ->helperText('Your shop link cannot be changed after approval.'),

                        FileUpload::make('logo')
                            ->label('Logo')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(5120)
                            ->disk(ShopImageStore::PUBLIC_DISK)
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(ShopImageStore::class)->storeLogo($file)),

                        Textarea::make('description')
                            ->label('Short description')
                            ->maxLength(200)
                            ->rows(3)
                            ->live(debounce: 300)
                            ->hint(fn (?string $state): string => strlen((string) $state).' / 200'),

                        Select::make('category')
                            ->options(array_combine(Vendor::BUSINESS_CATEGORIES, Vendor::BUSINESS_CATEGORIES))
                            ->required(),

                        Select::make('state')
                            ->options(array_combine(NigerianStates::ALL, NigerianStates::ALL))
                            ->searchable()
                            ->required(),

                        TextInput::make('city')
                            ->label('City / Area')
                            ->maxLength(100)
                            ->required(),

                        TextInput::make('whatsapp')
                            ->label('WhatsApp number')
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail) use ($vendor) {
                                $number = WhatsAppNumber::normalise($value);

                                if (! $number) {
                                    $fail('Enter a valid Nigerian WhatsApp number, e.g. 0803 123 4567.');
                                } elseif (FreeShopApplications::whatsAppInUse($number, $vendor->id)) {
                                    $fail('This WhatsApp number is already used by another shop.');
                                }
                            }),
                    ]),

                // The owner's own sign-in. Hidden from a platform admin
                // looking in: they reset passwords from Free Shop Applications.
                Section::make('Account')
                    ->description('You can always sign in with your WhatsApp number. Adding an email lets you sign in with it too, and lets us email you about your shop.')
                    ->visible(fn (): bool => self::viewerIsOwner())
                    ->schema([
                        TextInput::make('email')
                            ->label('Email (optional)')
                            ->email()
                            ->maxLength(255)
                            ->unique(table: 'users', column: 'email', ignorable: fn () => filament()->getTenant()?->user)
                            ->validationMessages(['unique' => 'That email already belongs to another account.']),

                        TextInput::make('current_password')
                            ->label('Current password')
                            ->password()
                            ->revealable()
                            ->requiredWith('new_password')
                            ->currentPassword()
                            ->helperText('Only needed to change your password.'),

                        TextInput::make('new_password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->rule(Password::default()),

                        TextInput::make('new_password_confirmation')
                            ->label('Confirm new password')
                            ->password()
                            ->revealable()
                            ->requiredWith('new_password')
                            ->same('new_password'),
                    ]),
            ])
            ->statePath('data');
    }

    private static function viewerIsOwner(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $user && $vendor && $vendor->isOwner($user);
    }

    public function save(): void
    {
        $data   = $this->form->getState();
        $vendor = filament()->getTenant();

        $oldLogo = $vendor->logo;

        $vendor->update([
            'logo'        => $data['logo'] ?: null,
            'description' => $data['description'] ?: null,
            'category'    => $data['category'],
            'state'       => $data['state'],
            'city'        => $data['city'],
            'whatsapp'    => WhatsAppNumber::normalise($data['whatsapp']),
        ]);

        if ($oldLogo && $oldLogo !== $vendor->logo) {
            app(ShopImageStore::class)->delete($oldLogo, ShopImageStore::PUBLIC_DISK);
        }

        $passwordChanged = false;

        if (self::viewerIsOwner()) {
            $owner = $vendor->user;

            // A real email replaces the placeholder; clearing it puts the
            // placeholder back, since users.email can never be empty.
            $email = filled($data['email'] ?? null)
                ? strtolower(trim($data['email']))
                : FreeShopApplications::placeholderEmailFor($owner);

            if ($email !== $owner->email) {
                $owner->forceFill(['email' => $email])->save();
            }

            if (filled($data['new_password'] ?? null)) {
                $owner->forceFill(['password' => $data['new_password']])->save();
                $passwordChanged = true;

                // Keeps this browser signed in under the new hash, which
                // AuthenticateSession would otherwise treat as a stale session.
                if (request()->hasSession()) {
                    request()->session()->put('password_hash_'.Filament::getAuthGuard(), $owner->getAuthPassword());
                }
            }

            $this->form->fill([
                ...$this->form->getRawState(),
                'current_password'          => null,
                'new_password'              => null,
                'new_password_confirmation' => null,
            ]);
        }

        Notification::make()
            ->title($passwordChanged ? 'Shop profile and password saved' : 'Shop profile saved')
            ->success()
            ->send();
    }
}
