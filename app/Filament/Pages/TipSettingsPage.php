<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\TipSetting;
use App\Policies\TipPolicy;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Platform-wide defaults for in-app tips. */
class TipSettingsPage extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Tip Settings';

    protected static ?string $title = 'In-App Tip Settings';

    protected static ?string $slug = 'tip-settings';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.tip-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Help Centre';
    }

    public static function canAccess(): bool
    {
        return TipPolicy::canManage(auth()->user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $setting = TipSetting::current();

        $this->form->fill([
            'daily_cap' => $setting->daily_cap,
            'cooldown_days' => $setting->cooldown_days,
            'adoption_window_days' => $setting->adoption_window_days,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('How often people see pop-ups')
                    ->schema([
                        TextInput::make('daily_cap')
                            ->label('Pop-ups per person per day')
                            ->numeric()->integer()->minValue(0)->maxValue(10)->required()
                            ->helperText('Counts tips, announcements and guided-tour offers together, per Lagos day. 0 switches every tip off.'),
                        TextInput::make('cooldown_days')
                            ->label('Days between repeats of the same tip')
                            ->numeric()->integer()->minValue(0)->maxValue(90)->required()
                            ->helperText('For tips set to show more than once. A tip can set its own.'),
                    ]),
                Section::make('Reporting')
                    ->schema([
                        TextInput::make('adoption_window_days')
                            ->label('“Used it” counts within (days)')
                            ->numeric()->integer()->minValue(1)->maxValue(90)->required()
                            ->helperText('Someone who first uses the feature this many days after first seeing the tip counts as “Used it”. A tip can set its own.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        TipSetting::current()->update([
            'daily_cap' => (int) $state['daily_cap'],
            'cooldown_days' => (int) $state['cooldown_days'],
            'adoption_window_days' => (int) $state['adoption_window_days'],
        ]);

        Notification::make()->title('Tip settings saved.')->success()->send();
    }
}
