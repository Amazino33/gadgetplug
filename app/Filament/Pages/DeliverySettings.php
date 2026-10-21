<?php

namespace App\Filament\Pages;

use App\Models\DeliveryRule;
use App\Models\Lga;
use App\Models\State;
use App\Models\Store;
use App\Services\Delivery\DeliveryDistance;
use App\Services\Delivery\DistanceBandPricer;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Delivery settings: where we deliver, for how much, from where.
 *
 * Every state has a default rule (free / fee / not delivered) and any LGA can
 * override it. A map shows each LGA coloured by the rule that applies and its
 * straight-line distance from the nearest store hub; a table lists the same by
 * state with bulk editing; and the price-by-distance helper turns distance
 * bands into LGA overrides after a preview.
 *
 * Every write re-checks access here — the page's own gate, not the button —
 * and every rule change is activity-logged on DeliveryRule.
 */
class DeliverySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Delivery settings';

    protected static ?string $title = 'Delivery settings';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.delivery-settings';

    /** Bands for the price-by-distance helper, as typed. */
    public array $bands = [
        ['from' => 0, 'to' => 15, 'mode' => 'free', 'fee' => null],
        ['from' => 15, 'to' => 40, 'mode' => 'fee', 'fee' => 1500],
        ['from' => 40, 'to' => 100, 'mode' => 'fee', 'fee' => 3500],
        ['from' => 100, 'to' => null, 'mode' => 'not_delivered', 'fee' => null],
    ];

    public ?int $bandScopeStateId = null;

    /** The last preview: what applying the bands would change. Display only. */
    public array $bandPreview = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->hasGlobalPermission('manage_delivery_settings'));
    }

    // ── What the page draws ─────────────────────────────────────────────────

    /**
     * Everything the map and table need, computed on the server so distances
     * and effective rules are the same numbers checkout uses.
     *
     * @return array<string, mixed>
     */
    public function deliveryData(): array
    {
        $distance = app(DeliveryDistance::class);
        $distance->forgetHubs();

        $rules = DeliveryRule::all();
        $stateRules = $rules->whereNull('lga_id')->keyBy('state_id');
        $overrides = $rules->whereNotNull('lga_id')->keyBy('lga_id');

        $states = State::orderBy('name')->get();

        $lgas = Lga::query()->orderBy('name')->get()->map(function (Lga $lga) use ($distance, $stateRules, $overrides) {
            $override = $overrides->get($lga->id);
            $rule = $override ?? $stateRules->get($lga->state_id);
            $nearest = $distance->nearestHub($lga);

            return [
                'id'            => $lga->id,
                'name'          => $lga->name,
                'state_id'      => $lga->state_id,
                'lat'           => (float) $lga->latitude,
                'lng'           => (float) $lga->longitude,
                'mode'          => $rule?->mode ?? DeliveryRule::NOT_DELIVERED,
                'fee'           => $rule && $rule->mode === DeliveryRule::FEE ? (float) $rule->fee : 0.0,
                'expected_cost' => $rule?->expected_cost !== null ? (float) $rule->expected_cost : null,
                'days'          => $rule?->delivery_days,
                'source'        => $override ? 'lga' : ($rule ? 'state' : 'none'),
                'km'            => $nearest ? round($nearest['km'], 1) : null,
                'hub_id'        => $nearest['store']->id ?? null,
            ];
        })->values();

        return [
            'states' => $states->map(fn (State $s) => [
                'id'            => $s->id,
                'name'          => $s->name,
                'lat'           => (float) $s->latitude,
                'lng'           => (float) $s->longitude,
                'mode'          => $stateRules->get($s->id)?->mode ?? DeliveryRule::NOT_DELIVERED,
                'fee'           => (float) ($stateRules->get($s->id)?->fee ?? 0),
                'expected_cost' => $stateRules->get($s->id)?->expected_cost !== null ? (float) $stateRules->get($s->id)->expected_cost : null,
                'days'          => $stateRules->get($s->id)?->delivery_days,
            ])->values()->all(),
            'lgas' => $lgas->all(),
            'hubs' => $distance->hubs()->map(fn (Store $store) => [
                'id'   => $store->id,
                'name' => $store->name,
                'lat'  => (float) $store->latitude,
                'lng'  => (float) $store->longitude,
            ])->values()->all(),
        ];
    }

    /** Every store, for placing hubs. */
    public function stores(): array
    {
        return Store::query()->with('vendor:id,name')->where('is_active', true)->orderBy('vendor_id')->orderBy('name')
            ->get(['id', 'vendor_id', 'name', 'latitude', 'longitude'])
            ->map(fn (Store $s) => [
                'id'     => $s->id,
                'label'  => ($s->vendor?->name ? $s->vendor->name.' — ' : '').$s->name,
                'lat'    => $s->latitude !== null ? (float) $s->latitude : null,
                'lng'    => $s->longitude !== null ? (float) $s->longitude : null,
            ])->all();
    }

    // ── Writes ──────────────────────────────────────────────────────────────

    public function saveLgaRule(int $lgaId, array $values): void
    {
        $this->authorizeWrite();

        $data = $this->validatedRule($values);
        DeliveryRule::setForLga(Lga::findOrFail($lgaId), $data);

        $this->refreshMap('Saved for '.Lga::find($lgaId)->name.'.');
    }

    public function saveStateRule(int $stateId, array $values): void
    {
        $this->authorizeWrite();

        $data = $this->validatedRule($values);
        DeliveryRule::setForState(State::findOrFail($stateId)->id, $data);

        $this->refreshMap('Default saved for '.State::find($stateId)->name.'.');
    }

    /** @param array<int, int> $lgaIds */
    public function bulkSetLgaRules(array $lgaIds, array $values): void
    {
        $this->authorizeWrite();

        $data = $this->validatedRule($values);

        foreach (Lga::whereKey($lgaIds)->get() as $lga) {
            DeliveryRule::setForLga($lga, $data);
        }

        $this->refreshMap(count($lgaIds).' LGA(s) updated.');
    }

    /** @param array<int, int> $lgaIds */
    public function clearOverrides(array $lgaIds): void
    {
        $this->authorizeWrite();

        // Through the model, so each removal is activity-logged.
        DeliveryRule::whereIn('lga_id', $lgaIds)->get()->each->delete();

        $this->refreshMap('Overrides cleared — those LGAs follow their state again.');
    }

    public function setHub(int $storeId, $latitude, $longitude): void
    {
        $this->authorizeWrite();

        $data = Validator::make(['lat' => $latitude, 'lng' => $longitude], [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ])->validate();

        Store::findOrFail($storeId)->update([
            'latitude'  => $data['lat'] !== null ? round((float) $data['lat'], 6) : null,
            'longitude' => $data['lng'] !== null ? round((float) $data['lng'], 6) : null,
        ]);

        $this->refreshMap($data['lat'] === null ? 'Hub removed.' : 'Hub pinned.');
    }

    public function previewBands(): void
    {
        $this->authorizeWrite();

        try {
            $this->bandPreview = app(DistanceBandPricer::class)->preview($this->bands, $this->bandScopeStateId)->all();
        } catch (InvalidArgumentException $e) {
            $this->bandPreview = [];
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function applyBands(): void
    {
        $this->authorizeWrite();

        try {
            $changed = app(DistanceBandPricer::class)->apply($this->bands, $this->bandScopeStateId);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->bandPreview = [];
        $this->refreshMap("{$changed} LGA(s) priced by distance.");
    }

    public function addBand(): void
    {
        $this->bands[] = ['from' => null, 'to' => null, 'mode' => 'fee', 'fee' => null];
    }

    public function removeBand(int $index): void
    {
        unset($this->bands[$index]);
        $this->bands = array_values($this->bands);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function authorizeWrite(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    private function validatedRule(array $values): array
    {
        return Validator::make($values, [
            'mode'          => ['required', 'in:'.implode(',', DeliveryRule::MODES)],
            // A fee on a free / not-delivered rule is ignored (DeliveryRule zeroes it).
            'fee'           => ['nullable', 'numeric', 'min:0', 'required_if:mode,fee'],
            'expected_cost' => ['nullable', 'numeric', 'min:0'],
            'delivery_days' => ['nullable', 'string', 'max:60'],
        ], [
            'fee.required_if' => 'A fee rule needs a fee.',
        ])->after(function ($validator) use ($values) {
            if (($values['mode'] ?? null) === DeliveryRule::FEE && (float) ($values['fee'] ?? 0) <= 0) {
                $validator->errors()->add('fee', 'A fee rule needs a fee above ₦0.');
            }
        })->validate();
    }

    private function refreshMap(string $message): void
    {
        Notification::make()->title($message)->success()->send();

        // The map lives in wire:ignore; it redraws itself from this.
        $this->dispatch('delivery-data-updated', data: $this->deliveryData());
    }
}
