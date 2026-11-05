<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\AssistantSetting;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AssistantSettings extends Page
{
    use InteractsWithForms;

    protected static string|null|\UnitEnum $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'AI Assistant';

    protected static ?string $title = 'AI Assistant Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleOvalLeftEllipsis;

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.vendor.pages.assistant-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'manage_settings')
        );
    }

    public function mount(): void
    {
        $user = auth()->user();
        $setting = AssistantSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'admin_disabled' => false,
                'vendor_disabled' => false,
            ]
        );

        $this->form->fill([
            'vendor_disabled' => $setting->vendor_disabled,
        ]);
    }

    public function form(Schema $form): Schema
    {
        $user = auth()->user();
        $setting = $user ? AssistantSetting::where('user_id', $user->id)->first() : null;
        $adminDisabled = (bool) ($setting?->admin_disabled);

        return $form
            ->schema([
                Callout::make('AI Assistant Blocked by Admin')
                    ->description('The AI Assistant is currently disabled for your account by a platform administrator. Please contact support if you need access.')
                    ->danger()
                    ->visible(fn (): bool => $adminDisabled),

                Section::make('Your AI Assistant Preferences')
                    ->description('Control whether the AI Assistant chat bubble appears for you in your vendor dashboard.')
                    ->schema([
                        Toggle::make('vendor_disabled')
                            ->label('Disable AI Assistant for my account')
                            ->helperText('Switch this on if you prefer not to see the chat bubble while working in the vendor panel.')
                            ->default(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $user = auth()->user();
        $state = $this->form->getState();

        /** @var AssistantSetting $setting */
        $setting = AssistantSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['admin_disabled' => false]
        );

        // Security invariant: Vendor cannot toggle admin_disabled
        $setting->update([
            'vendor_disabled' => (bool) ($state['vendor_disabled'] ?? false),
        ]);

        Notification::make()
            ->title('AI Assistant settings saved.')
            ->success()
            ->send();
    }
}
