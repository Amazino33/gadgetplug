<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Services\ActiveStore;
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

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Sales Report';

    protected static ?string $title = 'Sales Report';

    protected static ?int $navigationSort = 2;

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
                        Select::make('store')
                            ->label('Store')
                            ->options(fn (): array => self::selectableStores())
                            ->default(null)
                            ->placeholder('All stores')
                            ->live()
                            // One branch means there is nothing to choose
                            // between, and an empty dropdown is just noise.
                            ->visible(fn (): bool => count(self::selectableStores()) > 1),

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
     * The branches this user may report on: an owner or super admin sees every
     * one, anyone else only those they are assigned to — the same rule that
     * decides which branch they can sell from.
     *
     * @return array<int, string>
     */
    protected static function selectableStores(): array
    {
        $vendor = filament()->getTenant();

        return ActiveStore::accessibleFor($vendor, auth()->user())
            ->pluck('name', 'id')
            ->all();
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

        // Null means every branch, which is what the report has always shown.
        // A branch this user cannot reach is ignored rather than honoured, so a
        // hand-edited filter cannot read another branch's takings.
        $storeId = $this->filters['store'] ?? null;
        $storeId = $storeId !== null && array_key_exists((int) $storeId, self::selectableStores())
            ? (int) $storeId
            : null;

        $summary = $reports->summary($vendorId, $period->from, $period->to, $storeId);

        return [
            'period' => $period,
            'summary' => $summary,
            'storeId' => $storeId,
            'storeName' => $storeId ? (self::selectableStores()[$storeId] ?? null) : null,
            'previous' => $reports->summary($vendorId, $previous->from, $previous->to, $storeId),
            'channels' => $reports->channelBreakdown($vendorId, $period->from, $period->to, $storeId),
            'stores' => $reports->storeBreakdown($vendorId, $period->from, $period->to),
            'topProducts' => $reports->topProducts($vendorId, $period->from, $period->to, 10, $storeId),
            'cashiers' => $reports->cashierBreakdown($vendorId, $period->from, $period->to, $storeId),
            'onlineStatuses' => $reports->onlineOrderStatusBreakdown($vendorId, $period->from, $period->to, $storeId),
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
