<?php

namespace App\Filament\Vendor\Pages;

use App\Models\VendorNotificationSetting;
use App\Services\Messaging\DailySummaryNotifier;
use App\Services\Messaging\StorekeeperNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class NotificationSettings extends Page
{
    protected static string|null|\UnitEnum $navigationGroup = 'Settings';
    use InteractsWithForms;

    protected string $view = 'filament.vendor.pages.notification-settings';

    protected static ?string $navigationLabel = 'WhatsApp Alerts';

    protected static ?string $title = 'WhatsApp Alerts';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleOvalLeft;

    protected static ?int $navigationSort = 8;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'manage_notification_settings')
        );
    }

    public function mount(): void
    {
        $settings = VendorNotificationSetting::forVendor(filament()->getTenant());

        $this->form->fill([
            'storekeeper_whatsapp'     => $settings->storekeeper_whatsapp,
            'owner_whatsapp'           => $settings->owner_whatsapp,
            'notify_daily_summary'     => $settings->notify_daily_summary,
            'daily_summary_time'       => $settings->daily_summary_time,
            'notify_new_order'         => $settings->notify_new_order,
            'notify_undispatched'      => $settings->notify_undispatched,
            'notify_low_stock'         => $settings->notify_low_stock,
            'notify_cancelled'         => $settings->notify_cancelled,
            'undispatched_after_hours' => $settings->undispatched_after_hours,
            'reminder_frequency'       => $settings->reminder_frequency,
            'quiet_hours_enabled'      => $settings->quiet_hours_enabled,
            'quiet_from'               => $settings->quiet_from,
            'quiet_until'              => $settings->quiet_until,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Storekeeper WhatsApp')
                    ->description('Where store alerts are sent. Storekeepers are usually on the floor rather than logged in, so WhatsApp is what actually reaches them.')
                    ->schema([
                        TextInput::make('storekeeper_whatsapp')
                            ->label('Storekeeper WhatsApp Number')
                            ->tel()
                            ->placeholder('08012345678')
                            ->maxLength(20)
                            ->helperText('Leave blank to switch all storekeeper alerts off. This is separate from your store\'s public WhatsApp number on the Store Profile page.'),
                    ]),

                Section::make('Owner daily summary')
                    ->description('A morning WhatsApp covering yesterday: what each store sold, what should be in the till versus the bank, expenses and procurement. Sent only to the number below.')
                    ->schema([
                        TextInput::make('owner_whatsapp')
                            ->label('Owner WhatsApp Number')
                            ->tel()
                            ->placeholder('08012345678')
                            ->maxLength(20)
                            ->helperText('This message contains takings, cost of goods and profit. Keep it off any shared or shop-floor phone. Leave blank to switch the summary off.'),

                        Toggle::make('notify_daily_summary')
                            ->label('Send the daily summary'),

                        TimePicker::make('daily_summary_time')
                            ->label('Send at')
                            ->seconds(false)
                            ->required()
                            ->helperText('Nigerian time (WAT). The summary always covers the previous day, so a morning time reports a day that has definitely finished. It is checked every 15 minutes, so it can arrive a few minutes after the time you set.'),
                    ]),

                Section::make('Which alerts to send')
                    ->description('Each alert can be switched on or off independently. The wording of every one is editable under Message Templates.')
                    ->schema([
                        Toggle::make('notify_new_order')
                            ->label('New order to pack')
                            ->helperText('Sent the moment an order is paid or confirmed, listing the items, customer and delivery address.'),

                        Toggle::make('notify_undispatched')
                            ->label('Reminder: orders still awaiting dispatch')
                            ->helperText('A recurring digest of every order that has not gone out yet. One message listing all of them, not one per order.')
                            ->live(),

                        Toggle::make('notify_low_stock')
                            ->label('Low stock')
                            ->helperText('Sent when products fall to or below their low-stock threshold. Only fires as part of the recurring check.'),

                        Toggle::make('notify_cancelled')
                            ->label('Order cancelled after payment')
                            ->helperText('So a packed order gets unpacked and the items go back on the shelf.'),
                    ]),

                Section::make('Reminder timing')
                    ->description('Applies to the recurring reminders only. Instant alerts always go out when the event happens.')
                    ->schema([
                        TextInput::make('undispatched_after_hours')
                            ->label('Treat an order as needing follow-up after')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(168)
                            ->suffix('hours')
                            ->required()
                            ->helperText('An order only appears in the reminder once it has sat unshipped for this long.'),

                        Select::make('reminder_frequency')
                            ->label('How often to remind')
                            ->options(VendorNotificationSetting::FREQUENCIES)
                            ->required()
                            ->helperText('Takes effect on the next hourly check — no need to wait for the current cycle.'),

                        Toggle::make('quiet_hours_enabled')
                            ->label('Respect quiet hours')
                            ->helperText('Nigerian time (WAT). Reminders are held back outside the hours below, so a stalled order never wakes anyone. Instant alerts are unaffected.')
                            ->live(),

                        TimePicker::make('quiet_from')
                            ->label('Send reminders from')
                            ->seconds(false)
                            ->required(fn ($get) => $get('quiet_hours_enabled'))
                            ->visible(fn ($get) => $get('quiet_hours_enabled')),

                        TimePicker::make('quiet_until')
                            ->label('Stop reminders at')
                            ->seconds(false)
                            ->required(fn ($get) => $get('quiet_hours_enabled'))
                            ->visible(fn ($get) => $get('quiet_hours_enabled')),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Sends yesterday's real figures, not a mock-up, so the owner can
            // check the numbers against the Reports page before trusting the
            // message. --force semantics: sends even on a day with no trading,
            // which is what makes it usable as a "does my number work" test.
            Action::make('sendTestSummary')
                ->label('Send test summary')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send yesterday\'s summary now?')
                ->modalDescription('Sends the daily summary for yesterday to the saved owner number, ignoring the scheduled time. Save your changes first.')
                ->action(function (): void {
                    $vendor = filament()->getTenant();
                    $settings = VendorNotificationSetting::forVendor($vendor);

                    if (! $settings->hasOwnerNumber()) {
                        Notification::make()
                            ->title('No owner number saved yet')
                            ->body('Enter an owner WhatsApp number and save before sending a test.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $message = app(DailySummaryNotifier::class)
                        ->send($vendor, now()->subDay()->startOfDay(), force: true);

                    if ($message === null) {
                        Notification::make()
                            ->title('Nothing sent')
                            ->body('The daily summary is switched off, or its message template is missing or inactive.')
                            ->warning()
                            ->send();

                        return;
                    }

                    match ($message->status) {
                        'sent' => Notification::make()
                            ->title('Test summary sent')
                            ->body('Sent to '.$message->to_number.'.')
                            ->success()
                            ->send(),

                        'failed' => Notification::make()
                            ->title('Test summary failed')
                            ->body($message->provider_response['error'] ?? 'Unknown error')
                            ->danger()
                            ->send(),

                        default => Notification::make()
                            ->title('Summary queued as '.$message->status)
                            ->warning()
                            ->send(),
                    };
                }),

            // Proves the number is reachable before anyone relies on these alerts.
            // Bypasses cadence and quiet hours, but still respects the toggles so
            // it exercises the same path a real reminder takes.
            Action::make('sendTestReminder')
                ->label('Send test reminder')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send a test reminder now?')
                ->modalDescription('Sends the "orders awaiting dispatch" digest to the saved storekeeper number, ignoring the schedule and quiet hours. Save your changes first.')
                ->action(function (): void {
                    $vendor = filament()->getTenant();
                    $settings = VendorNotificationSetting::forVendor($vendor);

                    if (! $settings->hasStorekeeperNumber()) {
                        Notification::make()
                            ->title('No storekeeper number saved yet')
                            ->body('Enter a number and save before sending a test.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $message = app(StorekeeperNotifier::class)->undispatchedReminder($vendor);

                    if ($message === null) {
                        Notification::make()
                            ->title('Nothing to remind about')
                            ->body('No orders are currently awaiting dispatch past your follow-up window, so there was nothing to send. Check the reminder toggle is on.')
                            ->info()
                            ->send();

                        return;
                    }

                    match ($message->status) {
                        'sent' => Notification::make()
                            ->title('Test reminder sent')
                            ->body('Sent to '.$message->to_number.'.')
                            ->success()
                            ->send(),

                        'failed' => Notification::make()
                            ->title('Test reminder failed')
                            ->body($message->provider_response['error'] ?? 'Unknown error')
                            ->danger()
                            ->send(),

                        default => Notification::make()
                            ->title('Reminder queued as '.$message->status)
                            ->warning()
                            ->send(),
                    };
                }),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        VendorNotificationSetting::forVendor(filament()->getTenant())->update([
            'storekeeper_whatsapp'     => $data['storekeeper_whatsapp'] ?: null,
            'owner_whatsapp'           => $data['owner_whatsapp'] ?: null,
            'notify_daily_summary'     => (bool) ($data['notify_daily_summary'] ?? false),
            'daily_summary_time'       => $data['daily_summary_time'] ?? '07:00',
            'notify_new_order'         => (bool) ($data['notify_new_order'] ?? false),
            'notify_undispatched'      => (bool) ($data['notify_undispatched'] ?? false),
            'notify_low_stock'         => (bool) ($data['notify_low_stock'] ?? false),
            'notify_cancelled'         => (bool) ($data['notify_cancelled'] ?? false),
            'undispatched_after_hours' => (int) ($data['undispatched_after_hours'] ?? 6),
            'reminder_frequency'       => $data['reminder_frequency'] ?? '3h',
            'quiet_hours_enabled'      => (bool) ($data['quiet_hours_enabled'] ?? false),
            'quiet_from'               => $data['quiet_from'] ?? '08:00',
            'quiet_until'              => $data['quiet_until'] ?? '20:00',
        ]);

        Notification::make()
            ->title('WhatsApp alert settings saved')
            ->success()
            ->send();
    }
}
