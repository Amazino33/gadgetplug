<?php

namespace App\Filament\Pages;

use App\Models\AffiliateSetting;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class AffiliateSettings extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Affiliate Settings';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.affiliate-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public function mount(): void
    {
        $data = AffiliateSetting::current()->only([
            'platform_default_rate',
            'platform_default_reseller_discount',
            'return_window_days',
            'cookie_window_days',
            'min_payout_amount',
            'inactivity_demotion_days',
            'margin_cap_fraction',
        ]);

        // Stored as a 0.00–1.00 fraction (CommissionService multiplies it
        // directly against margin); shown here as a 0–100 percentage to
        // match every other rate field on this page.
        $data['margin_cap_fraction'] = ((float) $data['margin_cap_fraction']) * 100;

        $this->form->fill($data);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('data')
            ->components([
                Section::make('Affiliate Program Settings')
                    ->schema([
                        TextInput::make('platform_default_rate')
                            ->label('Platform Default Commission Rate (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required()
                            ->helperText('Used when a product has no override and its category has no rate set.'),

                        TextInput::make('platform_default_reseller_discount')
                            ->label('Platform Default Reseller Discount (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required()
                            ->helperText('Used when a product has no discount override and its category has no discount set.'),

                        TextInput::make('return_window_days')
                            ->label('Return / Hold Window (days)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText('How long a commission sits in "return window" after delivery before it clears to available.'),

                        TextInput::make('cookie_window_days')
                            ->label('Attribution Cookie Window (days)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('How long a click is remembered for last-click attribution.'),

                        TextInput::make('min_payout_amount')
                            ->label('Minimum Payout Amount (₦)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₦')
                            ->required()
                            ->helperText('An affiliate needs at least this much available balance to be eligible for a payout batch.'),

                        TextInput::make('inactivity_demotion_days')
                            ->label('Inactivity Demotion Window (days)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->helperText('An affiliate with no qualifying sale in this many days drops one level. Continued inactivity keeps dropping them one level per additional full window.'),

                        TextInput::make('margin_cap_fraction')
                            ->label('Level Boost Margin Cap (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required()
                            ->helperText('A level-boosted commission can never exceed this fraction of the item\'s margin — protects thin-margin categories regardless of level.'),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['margin_cap_fraction'] = ((float) $data['margin_cap_fraction']) / 100;

        AffiliateSetting::current()->update($data);

        Notification::make()
            ->title('Affiliate settings saved.')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }
}
