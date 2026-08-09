<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Services\Reporting\FinancialReportService;
use App\Services\Reporting\ReportPeriod;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use UnitEnum;

class FinancialReport extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|null|UnitEnum $navigationGroup = 'Store';

    protected static ?string $navigationLabel = 'Financial Report';

    protected static ?string $title = 'Financial Report';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.vendor.pages.financial-report';

    /** @var array<string, mixed> */
    public ?array $filters = [];

    public function mount(): void
    {
        $this->form->fill(['preset' => ReportPeriod::DEFAULT_PRESET]);
    }

    // Same preset control as Sales Report — reused, not forked, per
    // ReportPeriod already covering every preset this page needs
    // (Today/Yesterday/This week/This month via its default/Custom).
    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
                        Select::make('preset')
                            ->label('Period')
                            ->options(ReportPeriod::PRESETS)
                            ->default(ReportPeriod::DEFAULT_PRESET)
                            ->selectablePlaceholder(false)
                            ->live(),

                        DatePicker::make('from')
                            ->label('From')
                            ->maxDate(now())
                            ->live()
                            ->visible(fn (Get $get): bool => $get('preset') === 'custom'),

                        DatePicker::make('to')
                            ->label('To')
                            ->maxDate(now())
                            ->live()
                            ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
                    ])
                    ->columns(['sm' => 2, 'lg' => 3]),
            ]);
    }

    // Surfaced right where it's shown — the balances block below — rather
    // than on a separate settings page, per Phase 0's proposal. Freely
    // editable at any time (like FinancialAccount's opening_balance), not
    // locked after first set; the helper text just cautions against
    // changing it once the business has real history.
    protected function getHeaderActions(): array
    {
        return [
            Action::make('setInitialCapital')
                ->label('Set Initial Capital')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->modalHeading('Set Initial Capital')
                ->modalDescription('The total value you started this business with — cash you put in, plus the value of any stock you bought to open with. This is a one-time reference figure, not a live balance: it does NOT update automatically and it is NOT the same as your current Bank or Cash balance below, which move as you trade. It only exists so this page can show you "started with X, currently holding Y — am I actually ahead?"')
                ->schema([
                    TextInput::make('initial_capital')
                        ->label('Total Starting Capital (₦)')
                        ->numeric()
                        ->prefix('₦')
                        ->minValue(0)
                        ->required()
                        ->helperText('Everything you started with combined — cash and opening stock together, not just what\'s in the bank today. Set this once, early on; you can still change it later, but doing so only moves this comparison, it never rewrites any past period\'s profit.'),
                ])
                ->fillForm(fn () => ['initial_capital' => filament()->getTenant()->initial_capital])
                ->action(function (array $data): void {
                    filament()->getTenant()->update(['initial_capital' => $data['initial_capital']]);

                    Notification::make()->title('Initial capital updated')->success()->send();
                }),
        ];
    }

    public function getViewData(): array
    {
        $vendorId = filament()->getTenant()->id;
        $period = ReportPeriod::fromFilters($this->filters);

        return [
            'period' => $period,
            'report' => app(FinancialReportService::class)->report($vendorId, $period->from, $period->to),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'manage_financial_reports')
        );
    }
}
