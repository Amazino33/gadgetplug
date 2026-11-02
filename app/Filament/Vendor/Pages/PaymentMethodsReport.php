<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Services\ActiveStore;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\SalesReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

// How shop customers paid, over any period and a day at a time. The period
// totals are the Sales report's own "Payment methods" section; this page adds
// the per-day table and a CSV of it. Every figure comes from
// SalesReportService, so the two screens cannot disagree.
class PaymentMethodsReport extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-credit-card';

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Payment Methods';

    protected static ?string $title = 'Payment Methods';

    // Shares 3 with Top Sellers, so it sits straight after the Sales report.
    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.vendor.pages.payment-methods-report';

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCsv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadCsv()),
        ];
    }

    /**
     * The per-day table exactly as shown, plus a totals row, for the
     * accountant's spreadsheet.
     */
    public function downloadCsv(): StreamedResponse
    {
        $period = ReportPeriod::fromFilters($this->filters);
        $storeId = $this->selectedStoreId();
        $days = app(SalesReportService::class)
            ->dailyPayments(filament()->getTenant()->id, $period->from, $period->to, $storeId);

        $storeName = $storeId ? self::selectableStores()[$storeId] : 'all-stores';
        $filename = 'payment-methods-'.Str::slug($storeName).'-'
            .$period->from->setTimezone(config('reporting.timezone'))->toDateString().'-to-'
            .$period->to->setTimezone(config('reporting.timezone'))->toDateString().'.csv';

        return response()->streamDownload(function () use ($days) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Cash', 'Card (POS)', 'Bank transfer', 'On credit', 'Total sales', 'Debts repaid', 'Refunds']);

            foreach ($days as $day) {
                fputcsv($out, [$day['date'], ...array_values($day['sales']), array_sum($day['sales']), $day['repayments'], $day['refunds']]);
            }

            fputcsv($out, [
                'Total',
                ...array_map(fn (string $method) => round($days->sum("sales.{$method}"), 2), SalesReportService::SALE_TENDERS),
                round($days->sum(fn (array $day) => array_sum($day['sales'])), 2),
                round($days->sum('repayments'), 2),
                round($days->sum('refunds'), 2),
            ]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The branches this user may report on — the same rule as the Sales report.
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

    // A branch this user cannot reach is ignored rather than honoured, so a
    // hand-edited filter cannot read another branch's takings.
    protected function selectedStoreId(): ?int
    {
        $storeId = $this->filters['store'] ?? null;

        return $storeId !== null && array_key_exists((int) $storeId, self::selectableStores())
            ? (int) $storeId
            : null;
    }

    public function getViewData(): array
    {
        $vendorId = filament()->getTenant()->id;
        $reports = app(SalesReportService::class);
        $period = ReportPeriod::fromFilters($this->filters);
        $storeId = $this->selectedStoreId();

        return [
            'period' => $period,
            'storeName' => $storeId ? (self::selectableStores()[$storeId] ?? null) : null,
            'payments' => $reports->paymentBreakdown($vendorId, $period->from, $period->to, $storeId),
            'days' => $reports->dailyPayments($vendorId, $period->from, $period->to, $storeId),
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
