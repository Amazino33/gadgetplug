<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\SalesReportService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use UnitEnum;

class SalesReport extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|null|UnitEnum $navigationGroup = 'Store';

    protected static ?string $navigationLabel = 'Sales Report';

    protected static ?string $title = 'Sales Report';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.vendor.pages.sales-report';

    /** @var array<string, mixed> */
    public ?array $filters = [];

    public function mount(): void
    {
        $this->form->fill(['preset' => ReportPeriod::DEFAULT_PRESET]);
    }

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

    /**
     * Everything the view renders, resolved through the same service the
     * dashboard widgets use so the two screens can never disagree.
     */
    public function getViewData(): array
    {
        $vendorId = filament()->getTenant()->id;
        $reports = app(SalesReportService::class);
        $period = ReportPeriod::fromFilters($this->filters);
        $previous = $period->previous();

        $summary = $reports->summary($vendorId, $period->from, $period->to);

        return [
            'period' => $period,
            'summary' => $summary,
            'previous' => $reports->summary($vendorId, $previous->from, $previous->to),
            'channels' => $reports->channelBreakdown($vendorId, $period->from, $period->to),
            'topProducts' => $reports->topProducts($vendorId, $period->from, $period->to, 10),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_inventory_reports')
        );
    }
}
