<?php

namespace App\Filament\Pages;

use App\Models\PlatformMessagingSetting;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\PhoneNumber;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

// Platform-owned WhatsApp settings, separate from each vendor's own page.
// Exists because "was this online order announced to a store?" is GadgetPlug's
// responsibility while GadgetPlug is handling online orders, and that cannot be
// answered from five separate vendor settings pages.
class MessagingSettings extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-chat-bubble-oval-left';

    protected static ?string $navigationLabel = 'WhatsApp';

    protected static ?string $title = 'Platform WhatsApp Settings';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.messaging-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(
            PlatformMessagingSetting::current()->only(['fallback_storekeeper_whatsapp'])
        );
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Fallback storekeeper number')
                    ->description('Where an online order\'s "pack this order" alert goes when the vendor has not set a storekeeper number of their own. Without it, that alert simply never sends and nothing shows it was missed.')
                    ->schema([
                        TextInput::make('fallback_storekeeper_whatsapp')
                            ->label('GadgetPlug Storekeeper WhatsApp Number')
                            ->tel()
                            ->placeholder('08012345678')
                            ->maxLength(20)
                            ->helperText('Vendors who have set their own number are unaffected — theirs is always used first. Leave blank to go back to sending nothing when a vendor is unconfigured.'),
                    ]),

                Section::make('Vendors without a storekeeper number')
                    ->description('These vendors rely on the fallback above for every online order.')
                    ->schema([
                        // Rendered as static text rather than a table: this is a
                        // prompt to go and fix something, not a record to manage.
                        \Filament\Forms\Components\Placeholder::make('unconfigured_vendors')
                            ->hiddenLabel()
                            ->content(fn (): string => $this->unconfiguredVendorSummary()),
                    ]),
            ])
            ->statePath('data');
    }

    private function unconfiguredVendorSummary(): string
    {
        $configured = VendorNotificationSetting::query()
            ->whereNotNull('storekeeper_whatsapp')
            ->where('storekeeper_whatsapp', '!=', '')
            ->pluck('vendor_id');

        $names = Vendor::query()
            ->whereNotIn('id', $configured)
            ->orderBy('name')
            ->pluck('name');

        if ($names->isEmpty()) {
            return 'Every vendor has their own storekeeper number set.';
        }

        return $names->map(fn (string $name): string => '• '.$name)->implode("\n");
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $raw  = $data['fallback_storekeeper_whatsapp'] ?: null;

        if (filled($raw) && strlen((string) PhoneNumber::toNigerianInternational($raw)) !== 13) {
            Notification::make()
                ->title('That does not look like a Nigerian number')
                ->body('Enter it like 08012345678.')
                ->danger()
                ->send();

            return;
        }

        PlatformMessagingSetting::current()->update([
            'fallback_storekeeper_whatsapp' => $raw,
        ]);

        Notification::make()
            ->title('Platform WhatsApp settings saved')
            ->success()
            ->send();
    }
}
