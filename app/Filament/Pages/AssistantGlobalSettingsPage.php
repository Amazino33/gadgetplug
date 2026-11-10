<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AssistantGlobalSetting;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssistantGlobalSettingsPage extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'AI Assistant Global';

    protected static ?string $title = 'AI Assistant Global Settings';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.assistant-global-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Assistant';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $setting = AssistantGlobalSetting::current();

        $this->form->fill([
            'is_enabled' => $setting->is_enabled,
            'daily_message_cap' => $setting->daily_message_cap ?? 100,
            'daily_cost_cap_micros' => $setting->daily_cost_cap_micros ?? 200000,
            'daily_vendor_cap' => $setting->daily_vendor_cap ?? 100,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('System-Wide AI Assistant Switch')
                    ->description('When disabled, the assistant chat shell is completely deactivated across all vendor panels.')
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label('Enable AI Assistant Globally')
                            ->helperText('Switch off to immediately disable the AI assistant for all users and hide the chat bubble.')
                            ->default(true),
                    ]),

                Section::make('Usage & Cost Backstops')
                    ->description('Control maximum daily vendor activity and prevent unexpected AI provider spend.')
                    ->schema([
                        TextInput::make('daily_vendor_cap')
                            ->label('Daily Questions per Business')
                            ->numeric()
                            ->minValue(0)
                            ->default(100)
                            ->helperText('Set to 0 for unlimited. Questions one business (all its staff together) can ask per day; resets at midnight Lagos time. A business can be given its own number on its Vendor page.'),

                        TextInput::make('daily_message_cap')
                            ->label('Daily Message Cap')
                            ->numeric()
                            ->minValue(0)
                            ->default(100)
                            ->helperText('Set to 0 for unlimited. Maximum assistant messages one user can send per day (a backstop under the business cap); resets at midnight Lagos time.'),

                        TextInput::make('daily_cost_cap_micros')
                            ->label('Daily Cost Cap (Micro-Dollars)')
                            ->numeric()
                            ->minValue(0)
                            ->default(200000)
                            ->helperText('Set to 0 for unlimited. Micro-dollars ($1 = 1,000,000 micros). 200,000 = $0.20.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $setting = AssistantGlobalSetting::current();
        $setting->update([
            'is_enabled' => (bool) ($state['is_enabled'] ?? false),
            'daily_message_cap' => isset($state['daily_message_cap']) ? (int) $state['daily_message_cap'] : 100,
            'daily_cost_cap_micros' => isset($state['daily_cost_cap_micros']) ? (int) $state['daily_cost_cap_micros'] : 200000,
            'daily_vendor_cap' => isset($state['daily_vendor_cap']) ? (int) $state['daily_vendor_cap'] : 100,
        ]);

        Notification::make()
            ->title('AI Assistant global settings saved.')
            ->success()
            ->send();
    }
}
