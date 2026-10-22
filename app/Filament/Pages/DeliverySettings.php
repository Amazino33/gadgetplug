<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Delivery\DeliveryRuleForm;
use App\Filament\Pages\Delivery\DeliveryStats;
use App\Filament\Pages\Delivery\HubsTable;
use App\Filament\Pages\Delivery\StateRulesTable;
use App\Models\DeliveryRule;
use App\Models\Lga;
use App\Models\State;
use App\Models\Store;
use App\Services\Delivery\DeliveryDistance;
use App\Services\Delivery\DistanceBandPricer;
use App\Services\Delivery\NigeriaBounds;
use App\Support\Plural;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Livewire\Attributes\On;

/**
 * Delivery settings: where we deliver, for how much, from where.
 *
 * Every state has a default rule (free / fee / not delivered) and any LGA can
 * override it. Top to bottom: a summary, a map of every LGA coloured by its
 * rule (tap one to edit it), the store hubs distances are measured from, the
 * rules by state with bulk editing, and the price-by-distance helper that
 * turns distance bands into LGA overrides after a preview and a confirm.
 *
 * Built from Filament's own components; only the Leaflet maps are custom
 * (resources/views/filament/admin/delivery). Every write re-checks access —
 * the page's own gate, not the button — and every rule change is
 * activity-logged on DeliveryRule.
 */
class DeliverySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Delivery settings';

    protected static ?string $title = 'Delivery settings';

    protected ?string $subheading = 'Where we deliver, what it costs the customer, and the stores distances are measured from.';

    protected static ?int $navigationSort = 6;

    /** The price-by-distance form: bands and the state they apply to. */
    public ?array $bandData = [];

    /** The last preview: what applying the bands would change. Display only. */
    public array $bandPreview = [];

    public const DEFAULT_BANDS = [
        ['from' => 0, 'to' => 15, 'mode' => 'free', 'fee' => null],
        ['from' => 15, 'to' => 40, 'mode' => 'fee', 'fee' => 1500],
        ['from' => 40, 'to' => 100, 'mode' => 'fee', 'fee' => 3500],
        ['from' => 100, 'to' => null, 'mode' => 'not_delivered', 'fee' => null],
    ];

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->hasGlobalPermission('manage_delivery_settings'));
    }

    public function mount(): void
    {
        $this->bandsForm->fill(['bands' => self::DEFAULT_BANDS, 'scope' => null]);
    }

    // ── The page ────────────────────────────────────────────────────────────

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->getWidgetsSchemaComponents([DeliveryStats::class]),

            Callout::make('No store is pinned yet')
                ->description('Distances can\'t be worked out until at least one store has a location. Pin one under Store locations below.')
                ->warning()
                ->visible(fn (): bool => app(DeliveryDistance::class)->hubs()->isEmpty()),

            Section::make('Map')
                ->description('Every LGA, coloured by the rule that applies there. Tap one to change it.')
                ->schema([
                    View::make('filament.admin.delivery.map')
                        ->viewData(fn (): array => ['data' => $this->deliveryData()]),
                ]),

            ...$this->getWidgetsSchemaComponents([HubsTable::class, StateRulesTable::class]),

            Section::make('Price by distance')
                ->description('Set distance bands, preview what would change, then apply. Applying writes ordinary LGA overrides you can still edit one by one — prices never move on their own.')
                ->schema([
                    EmbeddedSchema::make('bandsForm'),

                    Actions::make([
                        $this->previewBandsAction(),
                        $this->applyBandsAction(),
                    ]),

                    Text::make(fn (): string => $this->previewSummary())
                        ->weight('semibold')
                        ->visible(fn (): bool => $this->bandPreview !== []),

                    RepeatableEntry::make('band_preview')
                        ->hiddenLabel()
                        // Scrolls inside its own box; a compact card per LGA on a phone.
                        ->extraAttributes(['class' => 'gp-band-preview'])
                        ->state(fn (): array => $this->previewRows())
                        ->visible(fn (): bool => $this->bandPreview !== [])
                        ->table([
                            TableColumn::make('LGA'),
                            TableColumn::make('Distance'),
                            TableColumn::make('Now'),
                            TableColumn::make('After'),
                        ])
                        ->schema([
                            TextEntry::make('lga')->label('LGA')->weight('medium'),
                            TextEntry::make('km')->label('Distance'),
                            TextEntry::make('now')->label('Now')->badge()->color(fn (string $state): string => self::labelColor($state)),
                            TextEntry::make('after')->label('After')->badge()->color(fn (string $state): string => self::labelColor($state)),
                        ]),
                ]),
        ]);
    }

    public function bandsForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('bandData')
            ->components([
                Repeater::make('bands')
                    ->label('Distance bands')
                    ->schema([
                        TextInput::make('from')
                            ->label('From')
                            ->suffix('km')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('to')
                            ->label('To')
                            ->suffix('km')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder('Beyond'),
                        Select::make('mode')
                            ->label('Rule')
                            ->options(DeliveryRule::LABELS)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live(),
                        TextInput::make('fee')
                            ->label('Fee')
                            ->prefix('₦')
                            ->numeric()
                            ->minValue(1)
                            ->required(fn (Get $get): bool => $get('mode') === DeliveryRule::FEE)
                            ->disabled(fn (Get $get): bool => $get('mode') !== DeliveryRule::FEE),
                    ])
                    ->columns(['default' => 2, 'md' => 4])
                    ->itemLabel(fn (array $state): string => self::bandLabel($state))
                    ->addActionLabel('Add band')
                    ->reorderable(false)
                    ->minItems(1)
                    ->helperText('"To" left blank means "and beyond". Distances are straight-line from the nearest store.'),

                Select::make('scope')
                    ->label('Apply to')
                    ->options(fn () => State::orderBy('name')->pluck('name', 'id'))
                    ->placeholder('All states')
                    ->searchable()
                    ->native(false),
            ]);
    }

    public function previewBandsAction(): Action
    {
        return Action::make('previewBands')
            ->label('Preview')
            ->icon('heroicon-m-eye')
            ->action(fn () => $this->previewBands());
    }

    public function applyBandsAction(): Action
    {
        return Action::make('applyBands')
            ->label('Apply')
            ->icon('heroicon-m-check')
            ->color('warning')
            ->visible(fn (): bool => $this->bandPreview !== [])
            ->requiresConfirmation()
            ->modalHeading('Apply these prices?')
            ->modalDescription(fn (): string => $this->previewSummary().' Each becomes an LGA override you can still edit.')
            ->modalSubmitActionLabel('Apply')
            ->action(fn () => $this->applyBands());
    }

    /** Tapping an LGA on the map: its details and its rule, in a sheet. */
    public function editLgaAction(): Action
    {
        return Action::make('editLga')
            ->modalHeading(fn (array $arguments): string => ($lga = $this->lgaFor($arguments)) ? $lga->name.', '.$lga->state->name : 'LGA')
            ->modalDescription(fn (array $arguments): string => $this->lgaSummary($this->lgaFor($arguments)))
            ->modalSubmitActionLabel('Save for this LGA')
            ->extraModalWindowAttributes(['class' => 'gp-phone-sheet'])
            ->stickyModalFooter()
            ->fillForm(fn (array $arguments): array => DeliveryRuleForm::fill($this->lgaFor($arguments)->effectiveRule()))
            ->schema(DeliveryRuleForm::fields())
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('resetLga')
                    ->label('Reset to state default')
                    ->color('gray')
                    ->visible(fn (): bool => (bool) $this->lgaFor($arguments)?->overrideRule)
                    ->cancelParentActions()
                    ->action(fn () => $this->clearOverrides([(int) $arguments['lga']])),
            ])
            ->action(fn (array $arguments, array $data) => $this->saveLgaRule((int) $arguments['lga'], $data));
    }

    // ── What the map draws ──────────────────────────────────────────────────

    /**
     * Everything the map needs, computed on the server so distances and
     * effective rules are the same numbers checkout uses.
     *
     * @return array<string, mixed>
     */
    public function deliveryData(): array
    {
        $distance = app(DeliveryDistance::class);
        $distance->forgetHubs();

        return [
            'states' => State::orderBy('name')->get(['id', 'name', 'latitude', 'longitude'])->map(fn (State $s) => [
                'id'   => $s->id,
                'name' => $s->name,
            ])->values()->all(),
            'lgas' => Lga::query()->with(['overrideRule', 'state.defaultRule'])->orderBy('name')->get()->map(function (Lga $lga) use ($distance) {
                $rule = $lga->effectiveRule();
                $nearest = $distance->nearestHub($lga);

                return [
                    'id'       => $lga->id,
                    'name'     => $lga->name,
                    'state_id' => $lga->state_id,
                    'lat'      => (float) $lga->latitude,
                    'lng'      => (float) $lga->longitude,
                    'mode'     => $rule['mode'],
                    'label'    => DeliveryRule::describe($rule['mode'], $rule['fee']),
                    'km'       => $nearest ? round($nearest['km'], 1) : null,
                    'hub_id'   => $nearest['store']->id ?? null,
                ];
            })->values()->all(),
            'hubs' => $distance->hubs()->map(fn (Store $store) => [
                'id'   => $store->id,
                'name' => $store->name,
                'lat'  => (float) $store->latitude,
                'lng'  => (float) $store->longitude,
            ])->values()->all(),
        ];
    }

    #[On('delivery-rules-changed')]
    public function refreshMapData(): void
    {
        // The map lives in wire:ignore; it redraws itself from this.
        $this->dispatch('delivery-data-updated', data: $this->deliveryData());
    }

    // ── Writes ──────────────────────────────────────────────────────────────

    public function saveLgaRule(int $lgaId, array $values): void
    {
        DeliveryRuleForm::authorize();

        $lga = Lga::findOrFail($lgaId);
        DeliveryRule::setForLga($lga, DeliveryRuleForm::validate($values));

        $this->changed('Saved for '.$lga->name.'.');
    }

    public function saveStateRule(int $stateId, array $values): void
    {
        DeliveryRuleForm::authorize();

        $state = State::findOrFail($stateId);
        DeliveryRule::setForState($state->id, DeliveryRuleForm::validate($values));

        $this->changed('Default saved for '.$state->name.'.');
    }

    /** @param array<int, int> $lgaIds */
    public function bulkSetLgaRules(array $lgaIds, array $values): void
    {
        DeliveryRuleForm::authorize();

        $data = DeliveryRuleForm::validate($values);

        foreach (Lga::whereKey($lgaIds)->get() as $lga) {
            DeliveryRule::setForLga($lga, $data);
        }

        $this->changed(count($lgaIds).' '.Plural::lgas(count($lgaIds)).' updated.');
    }

    /** @param array<int, int> $lgaIds */
    public function clearOverrides(array $lgaIds): void
    {
        DeliveryRuleForm::authorize();

        // Through the model, so each removal is activity-logged.
        DeliveryRule::whereIn('lga_id', $lgaIds)->get()->each->delete();

        $this->changed('Overrides cleared — those LGAs follow their state again.');
    }

    public function setHub(int $storeId, $latitude, $longitude): void
    {
        DeliveryRuleForm::authorize();

        $data = Validator::make(['lat' => $latitude, 'lng' => $longitude], [
            'lat' => ['nullable', 'required_with:lng', ...NigeriaBounds::latitudeRules()],
            'lng' => ['nullable', 'required_with:lat', ...NigeriaBounds::longitudeRules()],
        ], [
            'lat.between' => 'That latitude is outside Nigeria.',
            'lng.between' => 'That longitude is outside Nigeria.',
        ])->validate();

        Store::findOrFail($storeId)->update([
            'latitude'  => $data['lat'] !== null ? round((float) $data['lat'], 6) : null,
            'longitude' => $data['lng'] !== null ? round((float) $data['lng'], 6) : null,
        ]);

        $this->changed($data['lat'] === null ? 'Hub removed.' : 'Hub pinned.');
    }

    /** Works out what the bands would change. Writes nothing. */
    public function previewBands(): void
    {
        DeliveryRuleForm::authorize();

        [$bands, $scope] = $this->validatedBands();

        try {
            $this->bandPreview = app(DistanceBandPricer::class)->preview($bands, $scope)->all();
        } catch (InvalidArgumentException $e) {
            $this->bandPreview = [];
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        if ($this->bandPreview === []) {
            Notification::make()->title('Nothing would change — every LGA already matches these bands.')->info()->send();
        }
    }

    /** Writes the bands as LGA overrides. Only ever reached through the confirm. */
    public function applyBands(): void
    {
        DeliveryRuleForm::authorize();

        [$bands, $scope] = $this->validatedBands();

        try {
            // Recomputed, not taken from the preview, so what is applied is
            // what the rules say now.
            $changed = app(DistanceBandPricer::class)->apply($bands, $scope);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->bandPreview = [];
        $this->changed($changed.' '.Plural::lgas($changed).' priced by distance.');
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return array{0: array<int, array<string, mixed>>, 1: ?int} */
    private function validatedBands(): array
    {
        $data = $this->bandsForm->getState();

        return [array_values($data['bands'] ?? []), filled($data['scope'] ?? null) ? (int) $data['scope'] : null];
    }

    private function changed(string $message): void
    {
        Notification::make()->title($message)->success()->send();

        $this->dispatch('delivery-rules-changed');
        $this->refreshMapData();
    }

    private function lgaFor(array $arguments): ?Lga
    {
        return Lga::with(['overrideRule', 'state.defaultRule'])->find($arguments['lga'] ?? null);
    }

    private function lgaSummary(?Lga $lga): string
    {
        if (! $lga) {
            return '';
        }

        $rule = $lga->effectiveRule();
        $nearest = app(DeliveryDistance::class)->nearestHub($lga);

        return collect([
            $nearest
                ? round($nearest['km'], 1).' km straight-line from '.$nearest['store']->name.' (road is longer)'
                : 'No store pinned yet, so no distance',
            'Now: '.DeliveryRule::describe($rule['mode'], $rule['fee']).' — '.match ($rule['source']) {
                'lga'   => 'this LGA\'s own rule',
                'state' => $lga->state->name.' default',
                default => 'no rule set',
            },
        ])->implode(' · ');
    }

    /** "74 LGAs would change: 12 free, 50 paid, 12 not delivered" */
    public function previewSummary(): string
    {
        $count = count($this->bandPreview);
        $by = collect($this->bandPreview)->countBy('to_mode');

        $parts = collect([
            DeliveryRule::FREE          => 'free',
            DeliveryRule::FEE           => 'paid',
            DeliveryRule::NOT_DELIVERED => 'not delivered',
        ])->filter(fn ($label, $mode) => ($by[$mode] ?? 0) > 0)
            ->map(fn ($label, $mode) => $by[$mode].' '.$label);

        return $count.' '.Plural::lgas($count).' would change: '.$parts->implode(', ').'.';
    }

    /** @return array<int, array<string, string>> */
    private function previewRows(): array
    {
        return collect($this->bandPreview)->map(fn (array $row) => [
            'lga'   => $row['lga'].', '.$row['state'],
            'km'    => $row['km'].' km',
            'now'   => DeliveryRule::describe($row['from_mode'], $row['from_fee']),
            'after' => DeliveryRule::describe($row['to_mode'], $row['to_fee']),
        ])->all();
    }

    /** "0–15 km: Free", "100 km and beyond: Not delivered" */
    private static function bandLabel(array $band): string
    {
        $from = filled($band['from'] ?? null) ? (float) $band['from'] : 0;
        $range = filled($band['to'] ?? null) ? $from.'–'.(float) $band['to'].' km' : $from.' km and beyond';

        return $range.': '.DeliveryRule::describe($band['mode'] ?? null, $band['fee'] ?? null);
    }

    private static function labelColor(string $label): string
    {
        return match ($label) {
            DeliveryRule::LABELS[DeliveryRule::FREE]          => DeliveryRule::COLORS[DeliveryRule::FREE],
            DeliveryRule::LABELS[DeliveryRule::NOT_DELIVERED] => DeliveryRule::COLORS[DeliveryRule::NOT_DELIVERED],
            default                                           => DeliveryRule::COLORS[DeliveryRule::FEE],
        };
    }
}
