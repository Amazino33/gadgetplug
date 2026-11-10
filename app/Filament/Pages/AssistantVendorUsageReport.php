<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Assistant\PreflightCheckAction;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantVendorDailyUsage;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 1.3: questions per business per Lagos day against its cap, with the
 * tokens (cache included) and cost behind them. Platform staff only.
 *
 * Filament fills closure arguments by name: $query, $record, $state, $data.
 */
class AssistantVendorUsageReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Usage by Business';

    protected static ?string $title = 'AI Assistant Usage by Business';

    protected static ?int $navigationSort = 97;

    protected string $view = 'filament.pages.assistant-vendor-usage-report';

    public static function getNavigationGroup(): ?string
    {
        return 'Assistant';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function table(Table $table): Table
    {
        $settings = AssistantGlobalSetting::current();

        return $table
            ->query(AssistantVendorDailyUsage::query()->with('vendor:id,name,assistant_daily_cap'))
            ->columns([
                TextColumn::make('vendor.name')->label('Business')->searchable()->sortable(),
                TextColumn::make('date')->label('Day (Lagos)')->date()->sortable(),
                TextColumn::make('question_count')
                    ->label('Questions')
                    ->numeric()
                    ->sortable()
                    ->description(function (AssistantVendorDailyUsage $record) use ($settings): string {
                        $cap = $record->vendor ? PreflightCheckAction::vendorCap($record->vendor, $settings) : 0;

                        return $cap > 0 ? "of {$cap}" : 'no limit';
                    })
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('input_tokens')->label('Tokens in')->numeric()->sortable()->summarize(Sum::make()->label('In')),
                TextColumn::make('output_tokens')->label('Tokens out')->numeric()->sortable()->summarize(Sum::make()->label('Out')),
                TextColumn::make('cache_read_tokens')->label('Cache read')->numeric()->sortable()->toggleable(),
                TextColumn::make('cache_write_tokens')->label('Cache write')->numeric()->sortable()->toggleable(),
                TextColumn::make('cost_micros')
                    ->label('Cost (USD)')
                    ->state(fn (AssistantVendorDailyUsage $record): string => '$'.number_format($record->cost_micros / 1_000_000, 4))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vendor_id')->label('Business')->relationship('vendor', 'name')->searchable(),
                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('date_from')->label('From'),
                        DatePicker::make('date_to')->label('To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['date_from'] ?? null, fn (Builder $q, $date) => $q->where('date', '>=', $date))
                        ->when($data['date_to'] ?? null, fn (Builder $q, $date) => $q->where('date', '<=', $date))),
            ])
            ->defaultSort('date', 'desc');
    }
}
