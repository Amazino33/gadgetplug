<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Did the tip work? For each person who saw it: did they tap it, and did they
 * then actually use the feature?
 *
 * "Used it" means the linked feature's first use (per FeatureRegistry, judged
 * per person or per business by the same rule as everywhere else) falls on or
 * after the person's first sighting and within the tip's adoption window.
 * Anyone already using the feature before they first saw it is "already
 * using": taken out of the funnel and counted on their own, because the tip
 * cannot claim them.
 */
class TipAdoption
{
    public const SAW = 'saw';

    public const TAPPED = 'tapped';

    public const USED = 'used';

    public const ALREADY_USING = 'already_using';

    public function __construct(private readonly FeatureRegistry $features) {}

    /**
     * One row per person per vendor who has been shown the tip.
     *
     * @return Collection<int, array{user_id: int, vendor_id: int|null, first_shown_at: CarbonImmutable, clicked_at: CarbonImmutable|null, first_used_at: CarbonImmutable|null, business_wide: bool, status: string}>
     */
    public function rows(Tip $tip): Collection
    {
        $tz = config('app.timezone', 'UTC');

        $seen = TipEvent::query()
            ->where('tip_id', $tip->id)
            ->whereIn('event', [TipEvent::SHOWN, TipEvent::CLICKED])
            ->groupBy('user_id', 'vendor_id', 'event')
            ->selectRaw('user_id, vendor_id, event, MIN(created_at) as first_at')
            ->toBase()
            ->get()
            ->groupBy(fn ($row) => $row->user_id.':'.($row->vendor_id ?? ''));

        $feature = $this->features->find($tip->feature_key);
        $vendorIds = $seen->flatten(1)->pluck('vendor_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $byVendor = $feature ? $feature->firstUseByVendor($vendorIds) : [];
        $byUser = $feature && $feature->supportsUserLevel() ? $feature->firstUseByUser($vendorIds) : [];
        $owners = Vendor::query()->whereIn('id', $vendorIds)->pluck('user_id', 'id')->all();
        $superAdmins = $this->superAdminIds($seen->flatten(1)->pluck('user_id')->unique()->all());
        $window = $tip->effectiveAdoptionWindowDays();

        return $seen->map(function (Collection $events) use ($feature, $byVendor, $byUser, $owners, $superAdmins, $window, $tz) {
            $shown = $events->firstWhere('event', TipEvent::SHOWN);
            $clicked = $events->firstWhere('event', TipEvent::CLICKED);

            if (! $shown) {
                // A click with no recorded sighting: only possible from a
                // preview, which logs nothing. Not part of the funnel.
                return null;
            }

            $userId = (int) $shown->user_id;
            $vendorId = $shown->vendor_id !== null ? (int) $shown->vendor_id : null;
            $firstShown = CarbonImmutable::parse($shown->first_at, $tz);
            $clickedAt = $clicked ? CarbonImmutable::parse($clicked->first_at, $tz) : null;

            $businessWide = $feature?->isBusinessWideFor(
                ($vendorId !== null && (int) ($owners[$vendorId] ?? 0) === $userId) || in_array($userId, $superAdmins, true)
            ) ?? true;

            $firstUsed = $vendorId === null ? null : ($businessWide
                ? ($byVendor[$vendorId] ?? null)
                : ($byUser[$vendorId][$userId] ?? null));

            $status = match (true) {
                $firstUsed !== null && $firstUsed->lt($firstShown) => self::ALREADY_USING,
                $firstUsed !== null && $firstUsed->lte($firstShown->addDays($window)) => self::USED,
                $clickedAt !== null => self::TAPPED,
                default => self::SAW,
            };

            return [
                'user_id' => $userId,
                'vendor_id' => $vendorId,
                'first_shown_at' => $firstShown,
                'clicked_at' => $clickedAt,
                'first_used_at' => $firstUsed,
                'business_wide' => $businessWide,
                'status' => $status,
            ];
        })->filter()->values();
    }

    /**
     * The funnel. saw/tapped/used all leave out the already-using. tapped is
     * everyone who tapped the button; used is everyone who went on to use the
     * feature in the window, tapped or not (some read the tip and find the page
     * themselves). Their row status says which: a tapper who used it is "used".
     *
     * @return array{saw: int, tapped: int, used: int, already_using: int}
     */
    public function summary(Tip $tip): array
    {
        $rows = $this->rows($tip);
        $funnel = $rows->where('status', '!=', self::ALREADY_USING);

        return [
            'saw' => $funnel->count(),
            'tapped' => $funnel->whereNotNull('clicked_at')->count(),
            'used' => $funnel->where('status', self::USED)->count(),
            'already_using' => $rows->where('status', self::ALREADY_USING)->count(),
        ];
    }

    /** @return array<int, int> */
    private function superAdminIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('model_has_roles.model_id', $userIds)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereNull('model_has_roles.team_id')
            ->where('roles.name', 'super_admin')
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
