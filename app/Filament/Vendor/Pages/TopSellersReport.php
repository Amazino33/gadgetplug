<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\Category;
use App\Services\Reporting\ProductVelocityService;
use App\Services\Reporting\ReportPeriod;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use UnitEnum;

// "Which products are actually moving" — ranked by units sold in a chosen
// period, not tied to stock level or restocking at all (that's the Restock
// report's job; this one answers a different question). Reuses
// ProductVelocityService::topSellers(), which shares its definition of
// "sold" with the restock/financial work, not a new one invented here.
class TopSellersReport extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-fire';

    protected static string|null|UnitEnum $navigationGroup = 'Store';

    protected static ?string $navigationLabel = 'Top Sellers';

    protected static ?string $title = 'Top Sellers';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.vendor.pages.top-sellers-report';

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

                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn () => $this->getCategories()->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder('All categories')
                            ->live(),
                    ])
                    ->columns(['sm' => 2, 'lg' => 4]),
            ]);
    }

    public function getCategories(): Collection
    {
        $vendorId = filament()->getTenant()->id;

        $categoryIds = \App\Models\Product::where('vendor_id', $vendorId)->distinct()->pluck('category_id');

        return Category::whereIn('id', $categoryIds)->orderBy('name')->get(['id', 'name']);
    }

    public function getViewData(): array
    {
        $vendorId = filament()->getTenant()->id;
        $period = ReportPeriod::fromFilters($this->filters);
        $categoryId = $this->filters['category_id'] ?? null;

        $rows = app(ProductVelocityService::class)->topSellers(
            vendorId: $vendorId,
            from: $period->from,
            to: $period->to,
            categoryId: $categoryId ? (int) $categoryId : null,
            limit: 50,
        );

        return [
            'period' => $period,
            'rows' => $rows,
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
