<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Policies\TipPolicy;
use App\Support\Engagement\Csv;
use App\Support\Engagement\FeatureAdoption;
use App\Support\Engagement\FeatureRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Feature Adoption: per feature, the share of businesses using it and how many
 * people have; then, one feature at a time, the businesses not using it yet
 * with the owner's name and number, so someone can call them.
 *
 * One page in two states (?feature=), like Help & Guides, so a drill-down is a
 * link that can be shared. Every figure comes from FeatureAdoption, which
 * reads the same registry as the tips and the Learn page.
 *
 * Its own global permission: the list holds every owner's phone number,
 * which writing tips doesn't need.
 */
class FeatureAdoptionPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const PERMISSION = 'view_feature_adoption';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|null|UnitEnum $navigationGroup = 'Help Centre';

    protected static ?string $navigationLabel = 'Feature Adoption';

    protected static ?string $title = 'Feature Adoption';

    protected static ?string $slug = 'feature-adoption';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.admin.adoption.page';

    #[Url(as: 'feature')]
    public ?string $featureKey = null;

    public static function canView(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || TipPolicy::holdsGlobally($user, self::PERMISSION));
    }

    public static function canAccess(): bool
    {
        return static::canView(auth()->user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        if ($this->featureKey !== null && ! app(FeatureRegistry::class)->find($this->featureKey)) {
            $this->featureKey = null;
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->featureKey ? 'Not using '.$this->featureTitle.' yet' : static::$title;
    }

    #[Computed]
    public function featureTitle(): string
    {
        $row = collect(app(FeatureAdoption::class)->summary())->firstWhere('key', $this->featureKey);

        return $row['title'] ?? (string) $this->featureKey;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    app(FeatureAdoption::class)->refresh();
                    $this->resetTable();
                    Notification::make()->title('Recounted from live data')->success()->send();
                }),
            Action::make('downloadCsv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->download()),
        ];
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->records(fn (?array $filters): Collection => $this->rows($filters))
            ->filters([
                SelectFilter::make('plan')
                    ->options([
                        FeatureAdoption::PLAN_PAID => 'Paid plan',
                        FeatureAdoption::PLAN_FREE => 'Free plan',
                        FeatureAdoption::PLAN_ALL => 'All plans',
                    ])
                    ->default(FeatureAdoption::PLAN_PAID)
                    ->selectablePlaceholder(false),
                Filter::make('joined')
                    ->label('Date joined')
                    ->schema([
                        DatePicker::make('joined_from')->label('Joined from'),
                        DatePicker::make('joined_until')->label('Joined until'),
                    ]),
            ])
            ->paginated(false);

        return $this->featureKey ? $this->notUsingTable($table) : $this->summaryTable($table);
    }

    private function summaryTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Feature')->weight('medium')->wrap(),
                ViewColumn::make('percent')->label('Businesses using it')->view('filament.admin.adoption.percent-bar'),
                TextColumn::make('users_using')
                    ->label('People')
                    ->state(fn (array $record): string => $record['users_using'] === null ? 'Whole business' : number_format($record['users_using']))
                    ->color(fn (array $record): ?string => $record['users_using'] === null ? 'gray' : null),
                TextColumn::make('helpful')
                    ->label('👍 on Learn page')
                    ->state(fn (array $record): string => $record['helpful']['percent'] === null ? '–' : $record['helpful']['percent'].'%')
                    ->description(fn (array $record): ?string => ($record['helpful']['yes'] + $record['helpful']['no']) > 0
                        ? "{$record['helpful']['yes']} 👍 · {$record['helpful']['no']} 👎" : null),
                TextColumn::make('learn_taps')->label('“Show me” taps')->numeric(),
            ])
            ->recordActions([
                Action::make('notUsing')
                    ->label('Not using yet')
                    ->icon('heroicon-o-phone')
                    ->url(fn (array $record): string => static::getUrl(['feature' => $record['key']])),
            ]);
    }

    private function notUsingTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor')->label('Business')->weight('medium')->wrap(),
                TextColumn::make('owner')->label('Owner')->placeholder('–'),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->placeholder('No number')
                    ->url(fn (array $record): ?string => $record['phone'] ? 'tel:'.preg_replace('/[^0-9+]/', '', $record['phone']) : null)
                    ->copyable(),
                TextColumn::make('joined_at')->label('Joined')->date('j M Y', 'Africa/Lagos')->placeholder('–'),
                TextColumn::make('plan')->badge()->color(fn (string $state): string => $state === 'Free' ? 'gray' : 'success'),
            ])
            ->emptyStateHeading('Every business in this list uses it');
    }

    /** @return array{plan?: string|null, joined_from?: string|null, joined_until?: string|null} */
    private function filterValues(?array $filters): array
    {
        return [
            'plan' => $filters['plan']['value'] ?? FeatureAdoption::PLAN_PAID,
            'joined_from' => $filters['joined']['joined_from'] ?? null,
            'joined_until' => $filters['joined']['joined_until'] ?? null,
        ];
    }

    private function rows(?array $filters): Collection
    {
        $adoption = app(FeatureAdoption::class);
        $values = $this->filterValues($filters);

        if ($this->featureKey) {
            return $adoption->notUsing($this->featureKey, $values)->keyBy('vendor_id');
        }

        return collect($adoption->summary($values))->keyBy('key');
    }

    /** What's on screen, with the same filters, as a CSV file. */
    private function download(): StreamedResponse
    {
        $rows = $this->rows($this->tableFilters);
        $date = now('Africa/Lagos')->format('Y-m-d');

        if ($this->featureKey) {
            $csv = Csv::build(
                ['Business', 'Owner', 'Phone', 'Joined', 'Plan'],
                $rows->map(fn (array $r) => [$r['vendor'], $r['owner'], $r['phone'], $r['joined_at']?->setTimezone('Africa/Lagos')->format('Y-m-d'), $r['plan']]),
            );
            $name = "not-using-{$this->featureKey}-{$date}.csv";
        } else {
            $csv = Csv::build(
                ['Feature', 'Businesses using', 'Businesses', 'Percent', 'People', 'Helpful yes', 'Helpful no', 'Show me taps'],
                $rows->map(fn (array $r) => [
                    $r['title'], $r['vendors_using'], $r['vendors_total'], $r['percent'],
                    $r['users_using'] ?? 'Whole business', $r['helpful']['yes'], $r['helpful']['no'], $r['learn_taps'],
                ]),
            );
            $name = "feature-adoption-{$date}.csv";
        }

        return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
