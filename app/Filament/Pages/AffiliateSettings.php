<?php

namespace App\Filament\Pages;

use App\Models\AffiliateSetting;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
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
            'click_rewards_enabled',
            'click_reward_amount',
            'click_reward_daily_cap',
            'click_reward_daily_ip_limit',
            'naira_per_point',
            'min_points_conversion',
            'share_timezone',
            'share_window_opens_at',
            'share_window_closes_at',
            'daily_share_points_cap',
            'streak_bonus_points',
            'streak_bonus_every_days',
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

                Section::make('Engaged Visit Rewards')
                    ->description('Paid when a referred visitor loads a second page — proof they saw the site and clicked on, rather than landing and leaving. This is separate from, and stacks with, the sale commission above.')
                    ->schema([
                        Toggle::make('click_rewards_enabled')
                            ->label('Pay for engaged visits')
                            ->helperText('Turn off to stop all traffic rewards immediately. Sale commissions are unaffected.'),

                        TextInput::make('click_reward_amount')
                            ->label('Reward per Engaged Visit (₦)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₦')
                            ->required()
                            ->helperText('Credited straight to the affiliate\'s available balance — there is no return window, since a visit cannot be reversed.'),

                        TextInput::make('click_reward_daily_cap')
                            ->label('Daily Cap per Affiliate (₦)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₦')
                            ->required()
                            ->helperText('Most an affiliate can earn from traffic in one day. Visits past the cap still count as engaged but pay nothing.'),

                        TextInput::make('click_reward_daily_ip_limit')
                            ->label('Rewarded Visits per IP per Day')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('Stops one person farming the reward by re-opening the same link. 1 means the same visitor is worth paying for once a day.'),
                    ])
                    ->columns(2),

                Section::make('Plug Points')
                    ->description('Task rewards are paid in Plug Points, a separate economy from commissions. Points only become spendable cash when the affiliate converts them, at the rate configured here.')
                    ->schema([
                        TextInput::make('naira_per_point')
                            ->label('Conversion Rate (₦ per point)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('₦')
                            ->required()
                            ->helperText('Frozen onto each conversion at the moment it happens, so changing it never restates what an affiliate already converted.'),

                        TextInput::make('min_points_conversion')
                            ->label('Minimum Points per Conversion')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->helperText('An affiliate cannot convert below this, which keeps the wallet ledger free of trivial dust credits.'),
                    ])
                    ->columns(2),

                Section::make('Daily Social Share')
                    ->description('Reward bands live under Affiliates → Reach Bands. These control when a share can be submitted and how much a day can pay.')
                    ->schema([
                        Select::make('share_timezone')
                            ->label('Share Timezone')
                            ->options([
                                'Africa/Lagos'   => 'Africa/Lagos (WAT)',
                                'Africa/Accra'   => 'Africa/Accra (GMT)',
                                'Africa/Nairobi' => 'Africa/Nairobi (EAT)',
                                'UTC'            => 'UTC',
                            ])
                            ->required()
                            ->helperText('The app runs on UTC, so the submission window and the streak\'s idea of "a day" are both resolved against this zone.'),

                        TextInput::make('daily_share_points_cap')
                            ->label('Daily Points Cap per Affiliate')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('Most Plug Points one affiliate can earn from shares in a single day, across however many submissions.'),

                        TimePicker::make('share_window_opens_at')
                            ->label('Window Opens')
                            ->seconds(false)
                            ->required(),

                        TimePicker::make('share_window_closes_at')
                            ->label('Window Closes')
                            ->seconds(false)
                            ->required()
                            ->helperText('A closing time earlier than the opening time is treated as a window that wraps past midnight.'),

                        TextInput::make('streak_bonus_points')
                            ->label('Streak Bonus (points)')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),

                        TextInput::make('streak_bonus_every_days')
                            ->label('Streak Bonus Every N Days')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->helperText('The bonus lands on every Nth consecutive share day. Missing a day resets the streak to zero.'),
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
