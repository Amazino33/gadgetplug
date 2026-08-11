<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Services\Reporting\Cards\AdEfficiencyCardProvider;
use App\Services\Reporting\Cards\CardSummary;
use App\Services\Reporting\Cards\DeadStockCardProvider;
use App\Services\Reporting\Cards\MoneyPositionCardProvider;
use App\Services\Reporting\Cards\ReportCardProvider;
use App\Services\Reporting\Cards\RestockCardProvider;
use App\Services\Reporting\Cards\SalesPulseCardProvider;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use UnitEnum;

// Presentation only — every figure on this page comes from an existing
// service via its ReportCardProvider (see app/Services/Reporting/Cards/).
// New cards slot in by adding a provider to CARD_PROVIDERS; nothing else on
// this page changes.
class ReportsHub extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|null|UnitEnum $navigationGroup = null;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Reports';

    // Ungrouped, right after the Dashboard — the landing surface for every
    // other report, not filed under any one of them.
    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.vendor.pages.reports-hub';

    /** @var array<class-string<ReportCardProvider>> */
    private const CARD_PROVIDERS = [
        RestockCardProvider::class,
        MoneyPositionCardProvider::class,
        SalesPulseCardProvider::class,
        AdEfficiencyCardProvider::class,
        DeadStockCardProvider::class,
    ];

    /**
     * @return Collection<int, CardSummary>
     */
    public function getCards(): Collection
    {
        $vendorId = filament()->getTenant()->id;

        return collect(self::CARD_PROVIDERS)
            ->map(function (string $providerClass) use ($vendorId) {
                try {
                    return app($providerClass)->summarize($vendorId);
                } catch (\Throwable $e) {
                    // One card's data source failing must not blank the
                    // whole page a vendor checks every day — render that
                    // tile as "unavailable" and keep the rest working.
                    Log::error("Reports hub card failed: {$providerClass}", ['exception' => $e->getMessage()]);

                    return new CardSummary(
                        key: $providerClass,
                        title: 'Unavailable',
                        headline: 'Could not load this card right now',
                        urgency: CardSummary::URGENCY_CALM,
                    );
                }
            });
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_reports_hub')
        );
    }
}
