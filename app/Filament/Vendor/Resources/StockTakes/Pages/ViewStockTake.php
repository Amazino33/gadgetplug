<?php

namespace App\Filament\Vendor\Resources\StockTakes\Pages;

use App\Filament\Vendor\Resources\StockTakes\StockTakeResource;
use App\Filament\Vendor\Resources\StockTakes\Widgets;
use App\Models\Aisle;
use App\Models\StockTake;
use App\Models\User;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\StockTakePlanner;
use App\Services\StockTake\StockTakeScope;
use App\Services\StockTake\StockTakeSpotCheck;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * A stock take's dashboard: how far it has got, its tasks, and the lists of
 * what is left — uncounted, not on any aisle, and barcodes nobody knew.
 */
class ViewStockTake extends ViewRecord
{
    protected static string $resource = StockTakeResource::class;

    public function getTitle(): string
    {
        /** @var StockTake $record */
        $record = $this->getRecord();

        return "{$record->reference} · {$record->name}";
    }

    public function getSubheading(): ?string
    {
        /** @var StockTake $record */
        $record = $this->getRecord();

        $carried = $record->carriedFrom ? " · Carried over from {$record->carriedFrom->reference}" : '';

        return ucfirst($record->status) . ' · ' . $record->start_date->format('d M') . ' – ' . $record->end_date->format('d M Y') . $carried;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            Widgets\TillSyncWarning::class,
            Widgets\StockTakeProgress::class,
            Widgets\StockTakeTotals::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return array_values(array_filter([
            Widgets\UncountedProducts::class,
            // With a Quick count the whole branch is in scope: "not on any aisle" would list everything.
            StockTakeScope::hasQuickCount($this->getRecord()) ? null : Widgets\NotMappedProducts::class,
            Widgets\UnknownItems::class,
            Widgets\CounterAccuracy::class,
            // Unseen until a number on the results is clicked: "what happened".
            Widgets\CountStoryPanel::class,
            // Unseen until "Link to existing" is clicked: the product picker.
            Widgets\LinkProductPanel::class,
        ]));
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            self::assignCountAction(),

            // A random share of the counts that looked fine, counted again by
            // someone else: catches the wrong count that happened to look right.
            Action::make('spot_check')
                ->label('Spot-check')
                ->color('gray')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->visible(fn () => $this->getRecord()->isOpen() && app(StockTakeSpotCheck::class)->candidates($this->getRecord())->isNotEmpty())
                ->modalHeading('Spot-check counts that looked fine')
                ->modalDescription(function () {
                    $n = app(StockTakeSpotCheck::class)->candidates($this->getRecord())->count();

                    return "{$n} count" . ($n === 1 ? '' : 's') . ' looked fine and are waiting for a decision. A random share is counted again by someone who did not count them. They see an ordinary recount, blind as always; Counter accuracy shows how the first counts held up.';
                })
                ->schema(fn () => [
                    \Filament\Forms\Components\Select::make('assigned_to')
                        ->label('Who checks')
                        ->options(fn () => self::checkers($this->getRecord()))
                        ->required(),
                    \Filament\Forms\Components\TextInput::make('percent')
                        ->label('Share to check (%)')
                        ->numeric()->minValue(1)->maxValue(100)
                        ->default(StockTakeSpotCheck::DEFAULT_PERCENT)
                        ->required(),
                    \Filament\Forms\Components\DatePicker::make('due_date')->label('Due')->default(\App\Support\Pos\BusinessDate::today())->native(false),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(StockTakeSpotCheck::class)->send(
                    auth()->user(),
                    $this->getRecord(),
                    User::findOrFail($data['assigned_to']),
                    $data['due_date'] ?? null,
                    (int) $data['percent'],
                ), 'Spot-check sent')),

            Action::make('close')
                ->label('Close stock take')
                ->color('gray')
                ->icon('heroicon-o-lock-closed')
                ->visible(fn () => $this->getRecord()->isOpen())
                ->requiresConfirmation()
                ->modalHeading('Close this stock take?')
                ->modalDescription(function () {
                    $left = StockTakeScope::progress($this->getRecord())['remaining'];

                    return $left === 0
                        ? 'Everything in scope has been counted.'
                        : "{$left} product" . ($left === 1 ? ' is' : 's are') . ' still uncounted. They stay uncounted; you can carry them into a new stock take afterwards.';
                })
                ->action(fn () => $this->attempt(fn () => app(StockTakePlanner::class)->close(auth()->user(), $this->getRecord()), 'Stock take closed')),

            Action::make('carry_over')
                ->label('Carry uncounted into a new stock take')
                ->color('gray')
                ->icon('heroicon-o-arrow-uturn-right')
                ->visible(fn () => ! $this->getRecord()->isOpen() && StockTakeScope::uncounted($this->getRecord())->isNotEmpty())
                ->modalDescription(fn () => 'The new stock take counts only the ' . StockTakeScope::uncounted($this->getRecord())->count() . ' products this one left uncounted.')
                ->fillForm(fn () => [
                    'name'                       => $this->getRecord()->name . ' — leftovers',
                    'variance_threshold_percent' => $this->getRecord()->variance_threshold_percent,
                    'variance_threshold_value'   => $this->getRecord()->variance_threshold_value,
                ])
                ->schema(StockTakeResource::openingFields())
                ->action(function (array $data) {
                    $next = $this->attempt(fn () => app(StockTakePlanner::class)->carryOver(auth()->user(), $this->getRecord(), $data));

                    if ($next) {
                        $this->redirect(StockTakeResource::getUrl('view', ['record' => $next]));
                    }
                }),
        ];
    }

    /** People who may count at this branch: a spot-check skips the products each one counted. */
    private static function checkers(StockTake $stockTake): array
    {
        $vendor = $stockTake->vendor;

        return $vendor->users()->get()
            ->push(User::find($vendor->user_id))
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => \App\Services\StockTake\StockTakeAccess::canPerform($u, $vendor->id, (int) $stockTake->store_id))
            ->sortBy('name')
            ->mapWithKeys(fn (User $u) => [(int) $u->id => $u->name])
            ->all();
    }

    /**
     * New count task: aisles, who, when — and the same-day warning, live as
     * aisles are ticked.
     */
    public static function assignCountAction(): Action
    {
        return Action::make('assign_count')
            ->label('New count task')
            ->icon('heroicon-o-plus')
            ->visible(fn ($livewire) => $livewire->getRecord()->isOpen())
            ->modalHeading('New count task')
            ->modalWidth('3xl')
            ->schema(fn ($livewire) => self::assignCountFields($livewire->getRecord()))
            ->action(function (array $data, $livewire) {
                // Quick count: no aisles; the system makes the task its own area.
                if (! empty($data['quick'])) {
                    $livewire->attempt(fn () => app(StockTakePlanner::class)->assignQuickCount(
                        auth()->user(),
                        $livewire->getRecord(),
                        User::findOrFail($data['assigned_to']),
                        $data['due_date'] ?? null,
                    ), 'Quick count assigned');

                    return;
                }

                $livewire->attempt(fn () => app(StockTakePlanner::class)->assignCount(
                    auth()->user(),
                    $livewire->getRecord(),
                    array_map('intval', $data['aisle_ids'] ?? []),
                    User::findOrFail($data['assigned_to']),
                    $data['due_date'] ?? null,
                ), 'Count task assigned');
            });
    }

    public static function assignCountFields(StockTake $stockTake): array
    {
        $aisles = Aisle::query()
            ->where('vendor_id', $stockTake->vendor_id)
            ->where('store_id', $stockTake->store_id)
            ->active()
            ->ordered()
            ->withCount('products')
            ->listed()
            ->get();
        $held = StockTakeScope::aisleHolders($stockTake);

        return [
            Toggle::make('quick')
                ->label('Quick count: count and map in one walk')
                ->helperText('Nothing needs setting up first. The phone asks which aisle they are in (they can add an aisle that is not on the list, or choose "No aisle"), asks again every 30 minutes, and maps each product they count to that aisle. What nobody counts shows under Uncounted products. If the same product is in two places, give both places to the same person.')
                ->default(false)
                ->live(),

            CheckboxList::make('aisle_ids')
                ->label('Aisles')
                ->visible(fn (Get $get) => ! $get('quick'))
                ->options($aisles->mapWithKeys(fn (Aisle $a) => [$a->id => $a->label()])->all())
                ->descriptions($aisles->mapWithKeys(fn (Aisle $a) => [
                    $a->id => isset($held[$a->id]) ? "In {$held[$a->id]}'s task" : "{$a->products_count} products mapped",
                ])->all())
                ->disableOptionWhen(fn (string $value) => isset($held[(int) $value]))
                ->columns(3)
                ->required(fn (Get $get) => ! $get('quick'))
                ->live(),

            Callout::make('Some of these products are also in other aisles')
                ->key('same_day_warning')
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle')
                ->visible(fn (Get $get) => ! $get('quick') && self::warnings($stockTake, $get('aisle_ids')) !== [])
                ->description(fn (Get $get) => self::warningHtml(self::warnings($stockTake, $get('aisle_ids'))))
                ->actions([
                    Action::make('add_warned_aisles')
                        ->label('Add these aisles to this task')
                        ->visible(fn (Get $get) => self::freeWarnedAisles($stockTake, $get('aisle_ids')) !== [])
                        ->action(fn (Get $get, Set $set) => $set('aisle_ids', array_values(array_unique(array_merge(
                            array_map('strval', $get('aisle_ids') ?? []),
                            array_map('strval', self::freeWarnedAisles($stockTake, $get('aisle_ids'))),
                        ))))),
                ]),

            Select::make('assigned_to')
                ->label('Who counts')
                ->options(fn () => self::counterOptions($stockTake))
                ->searchable()
                ->required()
                ->helperText('People who may count at this branch (Perform stock takes).'),

            DatePicker::make('due_date')->label('Due')->default(\App\Support\Pos\BusinessDate::today())->native(false),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function warnings(StockTake $stockTake, $aisleIds): array
    {
        return StockTakeScope::sameDayWarnings($stockTake, array_map('intval', (array) ($aisleIds ?? [])));
    }

    /** Aisles named in the warning that no other open task holds. */
    private static function freeWarnedAisles(StockTake $stockTake, $aisleIds): array
    {
        return collect(self::warnings($stockTake, $aisleIds))
            ->flatMap(fn ($w) => $w['elsewhere'])
            ->whereNull('held_by')
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    private static function warningHtml(array $warnings): HtmlString
    {
        $lines = collect($warnings)->map(function ($w) {
            $places = collect($w['elsewhere'])
                ->map(fn ($a) => e($a['label']) . ($a['held_by'] ? ' <em>(in ' . e($a['held_by']) . '\'s task)</em>' : ''))
                ->implode(', ');

            return '<li><strong>' . e($w['product']) . '</strong> is also in ' . $places . '</li>';
        })->implode('');

        return new HtmlString(
            '<p>All the places a product sits should be counted the same day, so stock moved between them '
            . 'in the meantime is not counted twice or missed.</p>'
            . '<ul class="mt-2 list-disc ps-5">' . $lines . '</ul>'
        );
    }

    /** @return array<int, string> */
    private static function counterOptions(StockTake $stockTake): array
    {
        $vendor = $stockTake->vendor;

        return $vendor->users()->get()
            ->push(User::find($vendor->user_id))
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => StockTakeAccess::canPerform($u, $vendor->id, (int) $stockTake->store_id))
            ->sortBy('name')
            ->mapWithKeys(fn (User $u) => [(int) $u->id => $u->name])
            ->all();
    }

    /**
     * Run a planner call; a refusal becomes a notification rather than an
     * error page. Returns what the call returned, or null if refused.
     */
    public function attempt(callable $call, ?string $success = null): mixed
    {
        try {
            $result = $call();
        } catch (ValidationException $e) {
            Notification::make()->title('Not done')->body(collect($e->errors())->flatten()->first())->danger()->send();

            return null;
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

            return null;
        }

        if ($success) {
            Notification::make()->title($success)->success()->send();
        }

        $this->getRecord()->refresh();
        $this->dispatch('stock-take-updated');

        return $result ?? true;
    }
}
