<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AssistantDailyUsage;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AssistantUsageReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Usage Report';

    protected static ?string $title = 'AI Assistant Usage Report';

    protected static ?int $navigationSort = 96;

    protected string $view = 'filament.pages.assistant-usage-report';

    public static function getNavigationGroup(): ?string
    {
        return 'Assistant';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTodayTotalCostUsd(): string
    {
        $micros = (int) AssistantDailyUsage::query()
            ->where('date', AssistantDailyUsage::day())
            ->sum('cost_micros');

        return '$'.number_format($micros / 1000000, 4);
    }

    public function getTodayTotalMessages(): int
    {
        return (int) AssistantDailyUsage::query()
            ->where('date', AssistantDailyUsage::day())
            ->sum('message_count');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AssistantDailyUsage::query()->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label('Vendor User')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('message_count')
                    ->label('Messages')
                    ->numeric()
                    ->sortable()
                    ->summarize(Sum::make()->label('Total Messages')),

                TextColumn::make('input_tokens')
                    ->label('Input Tokens')
                    ->numeric()
                    ->sortable()
                    ->summarize(Sum::make()->label('Total In')),

                TextColumn::make('output_tokens')
                    ->label('Output Tokens')
                    ->numeric()
                    ->sortable()
                    ->summarize(Sum::make()->label('Total Out')),

                TextColumn::make('cost_micros')
                    ->label('Cost (USD)')
                    ->state(fn (AssistantDailyUsage $record): string => '$'.number_format($record->cost_micros / 1000000, 4))
                    ->sortable(),
            ])
            ->filters([
                Filter::make('date_range')
                    ->form([
                        DatePicker::make('date_from')->label('From Date'),
                        DatePicker::make('date_to')->label('To Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['date_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['date_to'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '<=', $date));
                    }),
            ])
            ->defaultSort('date', 'desc');
    }
}
