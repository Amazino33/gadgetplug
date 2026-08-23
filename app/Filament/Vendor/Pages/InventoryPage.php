<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Filament\Vendor\Widgets\InventoryOverviewWidget;
use App\Filament\Vendor\Widgets\InventoryTableWidget;
use App\Filament\Vendor\Widgets\StockMovementChart;
use App\Models\Store;
use App\Services\ActiveStore;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use BackedEnum;

class InventoryPage extends Page
{
    protected static string|null|\UnitEnum $navigationGroup = 'Inventory';
    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Inventory';
    protected static ?string $title           = 'Inventory & Stock Evaluation';
    protected static ?int $navigationSort = 1;
    protected string  $view            = 'filament.vendor.pages.inventory';

    /**
     * Which branch this screen is reporting on. Null means every branch the
     * viewer can reach — the whole-business figure.
     *
     * This screen carries its own selector rather than following the topbar
     * switcher like Products and the till do. Everywhere else, "which branch am
     * I working in" is the only question. Here the owner also needs "what is
     * the business worth in total", and a screen that could only ever answer
     * one branch at a time would make them add the branches up by hand.
     */
    public ?int $storeFilter = null;

    public function mount(): void
    {
        // Opens on the branch you are working in, so the numbers agree with
        // the rest of the panel until you deliberately widen them.
        $this->storeFilter = ActiveStore::currentId();
    }

    /**
     * Branches this viewer may report on — the same accessible-stores rule the
     * switcher and the grid use, so this screen can never widen someone's
     * reach beyond what they are assigned to.
     *
     * @return Collection<int, Store>
     */
    public function selectableStores(): Collection
    {
        return ActiveStore::accessibleFor(filament()->getTenant(), auth()->user());
    }

    // Passed into every widget on this page. Livewire re-sends it whenever the
    // selector changes; the widgets mark the property #[Reactive] so they
    // actually re-render rather than keeping the value they mounted with.
    public function getWidgetData(): array
    {
        return ['storeFilter' => $this->storeFilter];
    }

    protected function getHeaderWidgets(): array
    {
        return [InventoryOverviewWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            InventoryTableWidget::class,
            StockMovementChart::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 1;
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
