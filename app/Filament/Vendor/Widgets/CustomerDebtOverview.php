<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource;
use App\Models\PosCustomerLedgerEntry;
use App\Services\ActiveStore;
use App\Services\Pos\CustomerDebtService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * What the store is still owed, and how much of it this person put out there.
 *
 * Deliberately not filtered by the dashboard's date range. Money owed is a
 * standing position, not something that happened during a period — a debt from
 * March is every bit as unrecovered in August, and hiding it because it falls
 * outside the selected window would be the one thing this card must never do.
 *
 * Every figure links through to the debt list, because the reason to look at
 * this card is almost always to go and collect something.
 */
class CustomerDebtOverview extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $vendor = Filament::getTenant();
        $user   = auth()->user();

        if (! $vendor || ! $user) {
            return [];
        }

        // Shown to everyone who can reach the page, storekeepers included:
        // staff who extend credit should see what the shop is carrying.
        if (! CustomerDebtResource::canAccess()) {
            return [];
        }

        $debt     = app(CustomerDebtService::class);
        $url      = CustomerDebtResource::getUrl('index');
        $vendorId = (int) $vendor->id;

        $stats = [
            Stat::make('Still owed — all stores', '₦' . number_format($debt->vendorOutstanding($vendorId), 2))
                ->description($this->customerCount($vendorId) . ' customer(s) yet to pay')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('danger')
                ->url($url),
        ];

        $storeId = ActiveStore::currentId();

        if ($storeId) {
            $stats[] = Stat::make(
                'Owed at ' . ($this->storeName($storeId) ?? 'this branch'),
                '₦' . number_format($this->storeOutstanding($vendorId, $storeId), 2),
            )
                // Said plainly: a customer who took credit at two branches is
                // counted in both, so the branch figures can exceed the total
                // above. Better to explain the overlap than to net it away and
                // have a branch believe it is owed less than it is.
                ->description('Customers who took credit here · counted at every branch they owe')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('warning')
                ->url($url);
        }

        $mine = $this->grantedBy($vendorId, (int) $user->id);

        $stats[] = Stat::make('Credit you gave', '₦' . number_format($mine, 2))
            ->description($mine > 0
                ? 'Still outstanding from your sales'
                : 'Nothing outstanding from your sales')
            ->descriptionIcon('heroicon-m-user')
            ->color($mine > 0 ? 'warning' : 'success')
            ->url($url);

        return $stats;
    }

    private function customerCount(int $vendorId): int
    {
        return app(CustomerDebtService::class)->outstandingByCustomer($vendorId)->count();
    }

    private function storeName(int $storeId): ?string
    {
        return DB::table('stores')->where('id', $storeId)->value('name');
    }

    /**
     * What customers who took credit at this branch still owe in total.
     *
     * Their whole remaining balance, matching the branch list exactly — see
     * CustomerDebtResource::scopedToStore() for why this is not a per-store sum
     * of ledger rows.
     */
    private function storeOutstanding(int $vendorId, int $storeId): float
    {
        $customerIds = PosCustomerLedgerEntry::query()
            ->where('vendor_id', $vendorId)
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE)
            ->where('store_id', $storeId)
            ->distinct()
            ->pluck('pos_customer_id');

        return round(
            app(CustomerDebtService::class)
                ->outstandingByCustomer($vendorId)
                ->filter(fn ($balance, $customerId) => $customerIds->contains($customerId))
                ->sum(),
            2,
        );
    }

    /**
     * Credit this staff member personally extended that is still out.
     *
     * Charges they created, less what has since come back from those same
     * customers — capped at zero per customer so somebody else's collection
     * cannot make a storekeeper's figure go negative.
     */
    private function grantedBy(int $vendorId, int $userId): float
    {
        $charged = PosCustomerLedgerEntry::query()
            ->where('vendor_id', $vendorId)
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE)
            ->where('created_by', $userId)
            ->selectRaw('pos_customer_id, SUM(amount) as total')
            ->groupBy('pos_customer_id')
            ->pluck('total', 'pos_customer_id');

        if ($charged->isEmpty()) {
            return 0.0;
        }

        $outstanding = app(CustomerDebtService::class)->outstandingByCustomer($vendorId);

        // Their share of what each customer still owes, never more than the
        // customer actually owes now — a debt part-paid is part-recovered
        // whoever took the money.
        // map(), not sum() with a callback: sum() passes only the value, so the
        // customer id needed to look up what they still owe never arrives.
        return round($charged->map(fn ($theirCharges, $customerId) => min(
            (float) $theirCharges,
            (float) ($outstanding[$customerId] ?? 0),
        ))->sum(), 2);
    }
}
