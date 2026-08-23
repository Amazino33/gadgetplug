<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\Category;
use App\Models\Product;
use App\Services\Reporting\ProductVelocityService;
use App\Services\Reporting\RestockAnalysisResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

// Current-state snapshot, not a date-range report — "what do I need to
// reorder today" doesn't have a from/to, it has a trailing window, which is
// a vendor-level setting (see the Restock Settings action below), not a
// per-view picker.
class RestockReport extends Page
{
    use InteractsWithForms;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Restock Report';

    protected static ?string $title = 'Restock Report';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.vendor.pages.restock-report';

    /** @var array<string, mixed> */
    public ?array $filters = [];

    public function mount(): void
    {
        $this->form->fill(['showAll' => false]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('search')
                            ->label('Search')
                            ->placeholder('Product name or SKU')
                            ->live(debounce: 400),

                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder('All categories')
                            ->live(),

                        Toggle::make('showAll')
                            ->label('Show healthy & dead stock too')
                            ->helperText('Off by default so this stays about what needs action.')
                            ->live(),
                    ])
                    ->columns(['sm' => 2, 'lg' => 3]),
            ]);
    }

    // Surfaced here rather than a separate settings page, same pattern as
    // Financial Report's "Set Initial Capital" — a vendor-level number tied
    // to the one report that reads it, editable any time.
    protected function getHeaderActions(): array
    {
        return [
            Action::make('restockSettings')
                ->label('Restock Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Restock Settings')
                ->modalDescription('These control every number on this report. Defaults are sensible for most stores — only change them if you know your own lead time or want a different cover target.')
                ->schema([
                    TextInput::make('restock_window_days')
                        ->label('Sales Window (days)')
                        ->helperText('How far back to look when working out how fast something sells. Default 30.')
                        ->numeric()->minValue(7)->maxValue(365)
                        ->placeholder('30'),

                    TextInput::make('restock_lead_time_days')
                        ->label('Supplier Lead Time (days)')
                        ->helperText('How long a reorder takes to arrive once you place it. Default 5.')
                        ->numeric()->minValue(1)->maxValue(90)
                        ->placeholder('5'),

                    TextInput::make('restock_target_cover_days')
                        ->label('Target Cover (days)')
                        ->helperText('How many days of stock a reorder should aim to leave you with. Default 30.')
                        ->numeric()->minValue(1)->maxValue(365)
                        ->placeholder('30'),

                    TextInput::make('restock_safety_buffer_days')
                        ->label('Safety Buffer (days)')
                        ->helperText('Extra cushion added to lead time before something is "Reorder now" rather than still "Healthy". Defaults to matching your lead time.')
                        ->numeric()->minValue(0)->maxValue(90)
                        ->placeholder('Same as lead time'),
                ])
                ->fillForm(fn () => [
                    'restock_window_days'        => filament()->getTenant()->restock_window_days,
                    'restock_lead_time_days'     => filament()->getTenant()->restock_lead_time_days,
                    'restock_target_cover_days'  => filament()->getTenant()->restock_target_cover_days,
                    'restock_safety_buffer_days' => filament()->getTenant()->restock_safety_buffer_days,
                ])
                ->action(function (array $data): void {
                    filament()->getTenant()->update($data);

                    Notification::make()->title('Restock settings updated')->success()->send();
                }),
        ];
    }

    public function getViewData(): array
    {
        $vendor = filament()->getTenant();
        $settings = $vendor->restockSettings();

        $windowDays = $settings['windowDays'];
        $leadTimeDays = $settings['leadTimeDays'];
        $targetCoverDays = $settings['targetCoverDays'];
        $safetyBufferDays = $settings['safetyBufferDays'];

        $categoryId = $this->filters['category_id'] ?? null;
        $search = trim((string) ($this->filters['search'] ?? ''));
        $showAll = (bool) ($this->filters['showAll'] ?? false);

        $results = app(ProductVelocityService::class)->forVendor(
            vendorId: $vendor->id,
            categoryId: $categoryId ? (int) $categoryId : null,
            windowDays: $windowDays,
            leadTimeDays: $leadTimeDays,
            targetCoverDays: $targetCoverDays,
            safetyBufferDays: $safetyBufferDays,
        );

        $products = Product::whereIn('id', $results->keys())
            ->with('category:id,name')
            ->get(['id', 'name', 'sku', 'category_id', 'cost_price'])
            ->keyBy('id');

        $rows = $results
            ->map(fn (RestockAnalysisResult $result) => [
                'result' => $result,
                'product' => $products->get($result->productId),
            ])
            ->filter(fn (array $row) => $row['product'] !== null)
            ->when($search !== '', fn ($rows) => $rows->filter(
                fn (array $row) => str_contains(strtolower($row['product']->name), strtolower($search))
                    || str_contains(strtolower((string) $row['product']->sku), strtolower($search))
            ))
            ->when(! $showAll, fn ($rows) => $rows->reject(
                fn (array $row) => in_array($row['result']->tier, [
                    RestockAnalysisResult::TIER_HEALTHY,
                    RestockAnalysisResult::TIER_DEAD_STOCK_CANDIDATE,
                ], true)
            ))
            ->sortBy([
                fn (array $a, array $b) => self::tierPriority($a['result']->tier) <=> self::tierPriority($b['result']->tier),
                fn (array $a, array $b) => ($a['result']->daysOfCover ?? PHP_FLOAT_MAX) <=> ($b['result']->daysOfCover ?? PHP_FLOAT_MAX),
            ])
            ->values();

        return [
            'rows' => $rows,
            'windowDays' => $windowDays,
            'leadTimeDays' => $leadTimeDays,
            'targetCoverDays' => $targetCoverDays,
        ];
    }

    private static function tierPriority(string $tier): int
    {
        return match ($tier) {
            RestockAnalysisResult::TIER_URGENT => 0,
            RestockAnalysisResult::TIER_REORDER_NOW => 1,
            RestockAnalysisResult::TIER_STARVED_REVIEW => 2,
            RestockAnalysisResult::TIER_WATCH => 3,
            RestockAnalysisResult::TIER_REVIEW => 4,
            RestockAnalysisResult::TIER_HEALTHY => 5,
            RestockAnalysisResult::TIER_DEAD_STOCK_CANDIDATE => 6,
            default => 7,
        };
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_restock_report')
        );
    }
}
