<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tips\Pages;

use App\Filament\Resources\Tips\TipResource;
use App\Models\Tip;
use App\Policies\TipPolicy;
use App\Support\Engagement\TipAdoption;
use App\Support\Engagement\TipReport;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The per-tip report: Saw → Tapped → Used it (plus Already using), thumbs,
 * plays, and the people behind the numbers, filterable and downloadable.
 *
 * @property Tip $record
 */
class ReportTip extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = TipResource::class;

    protected string $view = 'filament.admin.tips.report';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /** Same rule as every other tip screen: tip managers only. */
    public static function canAccess(array $parameters = []): bool
    {
        return TipPolicy::canManage(auth()->user());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Report: '.$this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Report';
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function summary(): array
    {
        return app(TipReport::class)->summary($this->record);
    }

    #[Computed]
    public function windowDays(): int
    {
        return $this->record->effectiveAdoptionWindowDays();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCsv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->download()),
            Action::make('edit')
                ->label('Edit tip')
                ->color('gray')
                ->url(fn (): string => TipResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Who saw it')
            ->description('One row per person per business. Times are Lagos time.')
            ->records(function (?array $filters, ?string $search, int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                $rows = $this->rows($filters, $search);
                $perPage = $recordsPerPage === 'all' ? max(1, $rows->count()) : (int) $recordsPerPage;

                return new LengthAwarePaginator(
                    $rows->forPage((int) $page, $perPage)->values(),
                    $rows->count(),
                    $perPage,
                    (int) $page,
                );
            })
            ->columns([
                TextColumn::make('vendor')->label('Business')->searchable()->wrap(),
                TextColumn::make('store')->label('Branch')->placeholder('–'),
                TextColumn::make('user')->label('Person')->searchable()->wrap(),
                TextColumn::make('role'),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (array $record): string => match ($record['status']) {
                        TipAdoption::USED => 'success',
                        TipAdoption::TAPPED => 'info',
                        TipAdoption::ALREADY_USING => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('first_shown_at')->label('First saw')->dateTime('j M Y, H:i', 'Africa/Lagos'),
                TextColumn::make('clicked_at')->label('Tapped')->dateTime('j M Y, H:i', 'Africa/Lagos')->placeholder('–'),
                TextColumn::make('first_used_at')
                    ->label('First used')
                    ->dateTime('j M Y, H:i', 'Africa/Lagos')
                    ->placeholder('–')
                    ->description(fn (array $record): ?string => $record['first_used_at'] ? 'by the '.strtolower($record['scope'] === 'Business' ? 'business' : 'person') : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(TipReport::STATUS_LABELS),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nobody has seen this tip yet');
    }

    private function rows(?array $filters, ?string $search)
    {
        return app(TipReport::class)->filtered($this->record, $filters['status']['value'] ?? null, $search);
    }

    /** The list as it is filtered on screen, as a CSV file. */
    private function download(): StreamedResponse
    {
        $csv = TipReport::csv($this->rows($this->tableFilters, $this->getTableSearch()));
        $name = 'tip-'.$this->record->id.'-report-'.now('Africa/Lagos')->format('Y-m-d').'.csv';

        return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
