<?php

namespace App\Filament\Pages;

use App\Models\FreePlanSetting;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

// The free plan's limits and who hears about new applications. Platform
// owned, so it lives here rather than on any vendor's settings.
class FreePlanSettings extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Free Plan Settings';

    protected static ?string $title = 'Free Plan Settings';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.free-plan-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(FreePlanSetting::current()->only([
            'free_plan_product_limit',
            'free_plan_photos_per_product',
            'admin_notification_email',
        ]));
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Limits')
                    ->description('What a free (self-signup) shop may add. Paid vendors are not affected.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('free_plan_product_limit')
                            ->label('Products per free shop')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->required()
                            ->helperText('A free shop cannot create more products than this. Lowering it does not remove products already added.'),

                        TextInput::make('free_plan_photos_per_product')
                            ->label('Photos per product')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(8)
                            ->required(),
                    ]),

                Section::make('Notifications')
                    ->schema([
                        TextInput::make('admin_notification_email')
                            ->label('Email for new applications')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('reviews@gadgetplug.com.ng')
                            ->helperText('Told about each new application and each update to one. Leave blank to send nothing — applications still appear under Free Shop Applications.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        FreePlanSetting::current()->update([
            'free_plan_product_limit'      => (int) $data['free_plan_product_limit'],
            'free_plan_photos_per_product' => (int) $data['free_plan_photos_per_product'],
            'admin_notification_email'     => filled($data['admin_notification_email'] ?? null) ? trim($data['admin_notification_email']) : null,
        ]);

        Notification::make()->title('Free plan settings saved')->success()->send();
    }
}
