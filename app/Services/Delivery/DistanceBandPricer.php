<?php

namespace App\Services\Delivery;

use App\Models\DeliveryRule;
use App\Models\Lga;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * "Price by distance": a shortcut for writing LGA overrides, not a live formula.
 *
 * The owner gives distance bands (e.g. 0–15 km free, 15–40 km ₦1,500, 40–100 km
 * ₦3,500, beyond that not delivered) and a scope (one state or everywhere).
 * preview() shows which LGAs would change and how, and writes nothing; apply()
 * turns exactly that into ordinary LGA overrides, which stay editable one by
 * one. Prices customers see therefore never move on their own.
 *
 * Distances are straight-line from the nearest hub (DeliveryDistance). LGAs
 * that fall in no band are left as they are.
 */
class DistanceBandPricer
{
    public function __construct(
        private readonly DeliveryDistance $distance,
        private readonly DeliveryPricing $pricing,
    ) {}

    /**
     * @param  array<int, array{from: mixed, to: mixed, mode: string, fee?: mixed}>  $bands
     * @return Collection<int, array<string, mixed>>  one row per LGA that would change
     */
    public function preview(array $bands, ?int $stateId = null): Collection
    {
        $bands = $this->normaliseBands($bands);

        if ($this->distance->hubs()->isEmpty()) {
            throw new InvalidArgumentException('Pin at least one store on the map first — distances are measured from the stores.');
        }

        return Lga::query()
            ->with('state')
            ->when($stateId, fn ($q) => $q->where('state_id', $stateId))
            ->orderBy('state_id')->orderBy('name')
            ->get()
            ->map(function (Lga $lga) use ($bands) {
                $km = $this->distance->kmFromNearestHub($lga);
                $band = collect($bands)->first(fn ($b) => $km >= $b['from'] && $km < $b['to']);

                if (! $band) {
                    return null;
                }

                $current = $this->pricing->quote($lga);
                $newFee = $band['mode'] === DeliveryRule::FEE ? $band['fee'] : 0.0;

                if ($current->mode === $band['mode'] && abs($current->fee - $newFee) < 0.005) {
                    return null;
                }

                return [
                    'lga_id'       => $lga->id,
                    'lga'          => $lga->name,
                    'state'        => $lga->state->name,
                    'km'           => $km,
                    'from_mode'    => $current->mode,
                    'from_fee'     => $current->fee,
                    'from_source'  => $current->source,
                    'to_mode'      => $band['mode'],
                    'to_fee'       => $newFee,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Write the previewed changes as LGA overrides. Recomputed here rather than
     * taken from the page, so what is applied is what the rules say now.
     * Expected cost and delivery days on an existing override are kept.
     *
     * @return int how many LGAs changed
     */
    public function apply(array $bands, ?int $stateId = null): int
    {
        $changes = $this->preview($bands, $stateId);

        foreach ($changes as $change) {
            $lga = Lga::findOrFail($change['lga_id']);
            $existing = DeliveryRule::where('lga_id', $lga->id)->first();

            DeliveryRule::setForLga($lga, [
                'mode'          => $change['to_mode'],
                'fee'           => $change['to_fee'],
                'expected_cost' => $existing?->expected_cost,
                'delivery_days' => $existing?->delivery_days,
            ]);
        }

        return $changes->count();
    }

    /** @return array<int, array{from: float, to: float, mode: string, fee: float}> */
    private function normaliseBands(array $bands): array
    {
        $clean = [];

        foreach ($bands as $band) {
            $from = (float) ($band['from'] ?? 0);
            $to   = filled($band['to'] ?? null) ? (float) $band['to'] : INF;
            $mode = (string) ($band['mode'] ?? '');
            $fee  = (float) ($band['fee'] ?? 0);

            if (! in_array($mode, DeliveryRule::MODES, true)) {
                throw new InvalidArgumentException('Each band needs a mode: free, fee or not delivered.');
            }

            if ($to <= $from) {
                throw new InvalidArgumentException("A band must end after it starts ({$from}–{$to} km).");
            }

            if ($mode === DeliveryRule::FEE && $fee <= 0) {
                throw new InvalidArgumentException('A fee band needs a fee above ₦0.');
            }

            $clean[] = ['from' => $from, 'to' => $to, 'mode' => $mode, 'fee' => round($fee, 2)];
        }

        if ($clean === []) {
            throw new InvalidArgumentException('Add at least one distance band.');
        }

        usort($clean, fn ($a, $b) => $a['from'] <=> $b['from']);

        return $clean;
    }
}
