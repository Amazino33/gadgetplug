<?php

namespace App\Filament\Vendor\Pages;

use App\Livewire\Vendor\HomeDashboard;
use App\Services\Reporting\ReportPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class Dashboard extends \Filament\Pages\Dashboard
{
    use HasFiltersForm;

    public function content(Schema $schema): Schema
    {
        // A free (self-signup) shop has no till, purchases or reports, so the
        // sales widgets would be empty boxes. It gets its own simple home.
        if (filament()->getTenant()?->isFreePlan()) {
            return $schema->components([
                \Filament\Schemas\Components\View::make('filament.vendor.free-shop-home'),
            ]);
        }

        return $schema
            ->components([
                Livewire::make(HomeDashboard::class),
                Section::make('Detailed Reports')
                    ->schema([
                        ...(method_exists($this, 'getFiltersForm') ? [$this->getFiltersFormContentComponent()] : []),
                        $this->getWidgetsContentComponent(),
                    ]),
            ]);
    }

    protected static string $routePath = '/dashboard';

    protected static ?string $slug = 'dashboard';

    protected static ?int $navigationSort = -2;

    // Every widget on this page reads these filters through
    // InteractsWithPageFilters and resolves them with ReportPeriod, so the stat
    // cards and the charts can't end up describing different windows of time.
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
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
                        ->visible(fn (Get $get): bool => $get('preset') === 'custom'),

                    DatePicker::make('to')
                        ->label('To')
                        ->maxDate(now())
                        ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
                ])
                ->columns(['sm' => 2, 'lg' => 3]),
        ]);
    }
}
