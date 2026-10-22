<?php

namespace App\Filament\Pages;

use App\Models\PlatformMessagingSetting;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\WhatsAppGate;
use Filament\Actions\Action;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use App\Filament\Widgets\WhatsAppHealthWidget;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
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

    /**
     * Health first (the brake: sending or paused, today's numbers), then the
     * settings. All Filament components; the old hand-built panel is gone.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->getWidgetsSchemaComponents([WhatsAppHealthWidget::class]),

            Callout::make('WhatsApp sending is PAUSED')
                ->description(fn (): string => trim(($this->health()['pause_reason'] ?? '').' Urgent messages are going by SMS; '
                    .$this->health()['held'].' other '.str('message')->plural($this->health()['held']).' waiting. Re-scan the QR code in Wawp, then press "Resume WhatsApp sending".'))
                ->danger()
                ->visible(fn (): bool => (bool) $this->health()['paused']),

            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
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
                        Text::make('Every vendor has their own storekeeper number set.')
                            ->color('success')
                            ->visible(fn (): bool => $this->unconfiguredVendors()->isEmpty()),
                        UnorderedList::make(fn (): array => $this->unconfiguredVendors()
                            ->map(fn (string $name) => Text::make($name))
                            ->all())
                            ->visible(fn (): bool => $this->unconfiguredVendors()->isNotEmpty()),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function unconfiguredVendors(): \Illuminate\Support\Collection
    {
        $configured = VendorNotificationSetting::query()
            ->whereNotNull('storekeeper_whatsapp')
            ->where('storekeeper_whatsapp', '!=', '')
            ->pluck('vendor_id');

        return Vendor::query()
            ->whereNotIn('id', $configured)
            ->orderBy('name')
            ->pluck('name');
    }

    /** The WhatsApp brake's state. Read fresh: "Resume" changes it mid-request. */
    public function health(): array
    {
        return app(WhatsAppGate::class)->health();
    }

    protected function getHeaderActions(): array
    {
        return [
            // After the Wawp session has been re-scanned: start sending again.
            // Everything held while paused goes back through the throttle.
            Action::make('resumeWhatsApp')
                ->label('Resume WhatsApp sending')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Do this only after re-scanning the QR code in Wawp. Messages held while paused will be sent again, spaced out as usual.')
                ->visible(fn () => app(WhatsAppGate::class)->isPaused())
                ->action(function () {
                    $count = app(WhatsAppGate::class)->resume(auth()->user());

                    Notification::make()
                        ->title('WhatsApp sending resumed')
                        ->body($count > 0 ? "{$count} held ".str('message')->plural($count).' queued again.' : 'Nothing was waiting.')
                        ->success()
                        ->send();
                }),
        ];
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
