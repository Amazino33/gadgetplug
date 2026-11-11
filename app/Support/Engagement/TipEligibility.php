<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\TipSetting;
use App\Models\User;
use App\Models\UserTourProgress;
use App\Support\Engagement\Conditions\ConditionRegistry;
use App\Support\Engagement\Moments\PendingMoments;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Picks the one tip (or announcement) to show this person on this page, or
 * none. The only place that decision is made.
 *
 * Checks, in order: live (status and dates) → panel → role → not a create/edit
 * page → moment pending (moment tips only) → feature reachable and not already
 * used → condition → button destination reachable → not retired (clicked,
 * dismissed, or an announcement already shown) → under its view limit → out of
 * cooldown → the daily cap. Survivors are ranked announcement → moment tip →
 * ordinary tip, then higher priority, then the one this person saw least
 * recently.
 *
 * The daily cap counts the guided tours' own first-visit offer as well, so a
 * person gets one pop-up a day across both systems.
 */
class TipEligibility
{
    public const SLOT_ANNOUNCEMENT = 0;

    public const SLOT_MOMENT = 1;

    public const SLOT_TIP = 2;

    /** @var array<string, array<int, string>> */
    private array $roleNames = [];

    public function __construct(
        private readonly FeatureRegistry $features,
        private readonly FeatureUsage $usage,
        private readonly ConditionRegistry $conditions,
        private readonly DestinationResolver $destinations,
        private readonly PendingMoments $pendingMoments,
    ) {}

    public function pick(TipContext $context): ?TipDecision
    {
        if ($this->dailyCapReached($context->user)) {
            return null;
        }

        $tips = Tip::query()->live()->get();

        if ($tips->isEmpty()) {
            return null;
        }

        $state = $this->stateFor($context, $tips);
        $moments = $this->moments($context);
        $decisions = [];

        foreach ($tips as $tip) {
            $buttonUrl = null;

            if ($this->reject($tip, $context, $state[$tip->id] ?? [], $moments, $buttonUrl) === null) {
                $decisions[] = new TipDecision($tip, $buttonUrl, $this->slot($tip));
            }
        }

        usort($decisions, function (TipDecision $a, TipDecision $b) use ($state): int {
            return [$a->slot, -$a->tip->priority, $this->lastSeen($state[$a->tip->id] ?? []), $a->tip->id]
                <=> [$b->slot, -$b->tip->priority, $this->lastSeen($state[$b->tip->id] ?? []), $b->tip->id];
        });

        return $decisions[0] ?? null;
    }

    /**
     * Why $tip would not show in $context, or null if it would. Ignores the
     * daily cap and the other tips competing for the slot. Used by tests and
     * by the admin's "Preview as me".
     */
    public function explain(Tip $tip, TipContext $context): ?string
    {
        $state = $this->stateFor($context, collect([$tip]));
        $buttonUrl = null;

        return $this->reject($tip, $context, $state[$tip->id] ?? [], $this->moments($context), $buttonUrl);
    }

    /** Pop-ups this person has already had today (Lagos day), tips and tour offers together. */
    public function shownToday(User $user): int
    {
        [$start] = BusinessDate::boundsFor(BusinessDate::today());

        $tips = TipEvent::query()
            ->where('user_id', $user->id)
            ->where('event', TipEvent::SHOWN)
            ->where('created_at', '>=', $start)
            ->count();

        // A tour's first-visit offer is a pop-up too. Its row is created at
        // the moment of the offer and only updated after that.
        $tours = UserTourProgress::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $start)
            ->count();

        return $tips + $tours;
    }

    public function dailyCapReached(User $user): bool
    {
        return $this->shownToday($user) >= TipSetting::dailyCap();
    }

    /**
     * @param  array<string, array{count: int, last: CarbonImmutable}>  $state  this tip's events for this person
     * @param  array<string, int>  $moments  pending moment key => value
     */
    private function reject(Tip $tip, TipContext $context, array $state, array $moments, ?string &$buttonUrl): ?string
    {
        if (! $this->isLive($tip)) {
            return 'not_live';
        }

        if (! in_array($context->panelId, (array) $tip->panels, true)) {
            return 'wrong_panel';
        }

        if (! $this->matchesRoles($tip, $context)) {
            return 'wrong_role';
        }

        if (! $tip->show_on_form_pages && $this->destinations->isFormPage($context->routeName)) {
            return 'form_page';
        }

        if ($tip->isMomentTip() && ($moments[$tip->moment] ?? 0) < max(1, (int) $tip->moment_threshold)) {
            return 'no_moment';
        }

        $feature = null;

        if ($tip->feature_key) {
            $feature = $this->features->find($tip->feature_key);

            if (! $feature) {
                return 'feature_missing';
            }

            if (! $context->vendor || ! $feature->canAccess($context->user, $context->vendor)) {
                return 'no_feature_access';
            }

            if (! $tip->isAnnouncement() && $tip->hide_if_feature_used
                && $this->usage->hasUsed($feature, $context->user, $context->vendor)) {
                return 'feature_already_used';
            }
        }

        $condition = $this->conditions->find($tip->condition);

        if (! $condition || ! $condition->passes($context, $tip->condition_value)) {
            return 'condition';
        }

        if ($tip->button_route) {
            $buttonUrl = $this->destinations->url($tip->button_route, (array) $tip->button_params, $context->vendor);

            if ($buttonUrl === null || ! $this->destinations->canAccess($tip->button_route, $context->user, $context->vendor)) {
                return 'no_destination_access';
            }
        } elseif ($tip->button_url) {
            $buttonUrl = $tip->button_url;
        } elseif ($feature && $context->vendor) {
            $buttonUrl = $feature->destinationUrl($context->vendor);
        }

        $shown = $state[TipEvent::SHOWN]['count'] ?? 0;

        foreach (TipEvent::RETIRING as $event) {
            if (isset($state[$event])) {
                return 'retired';
            }
        }

        if ($tip->isAnnouncement() && $shown > 0) {
            return 'retired';
        }

        $limit = $tip->viewLimit();

        if ($limit !== null && $shown >= $limit) {
            return 'max_views';
        }

        $cooldown = $tip->effectiveCooldownDays();
        $lastSeen = $this->lastSeenAt($state);

        if ($cooldown > 0 && $lastSeen && $lastSeen->addDays($cooldown)->isFuture()) {
            return 'cooldown';
        }

        return null;
    }

    private function isLive(Tip $tip): bool
    {
        return $tip->status === Tip::STATUS_ACTIVE
            && ! $tip->trashed()
            && ($tip->starts_at === null || $tip->starts_at->lte(now()))
            && ($tip->ends_at === null || $tip->ends_at->gt(now()));
    }

    private function slot(Tip $tip): int
    {
        return match (true) {
            $tip->isAnnouncement() => self::SLOT_ANNOUNCEMENT,
            $tip->isMomentTip() => self::SLOT_MOMENT,
            default => self::SLOT_TIP,
        };
    }

    private function matchesRoles(Tip $tip, TipContext $context): bool
    {
        $wanted = array_values(array_filter((array) $tip->roles));

        if ($wanted === []) {
            return true;
        }

        if (! $context->vendor) {
            return false;
        }

        if (in_array(Tip::ROLE_OWNER, $wanted, true) && $context->vendor->isOwner($context->user)) {
            return true;
        }

        return array_intersect($wanted, $this->roleNamesAt($context)) !== [];
    }

    /**
     * The person's role names at this vendor, read straight from the pivot.
     * hasRole() would answer for whichever team the permission resolver
     * happens to hold, which is not necessarily this one.
     *
     * @return array<int, string>
     */
    private function roleNamesAt(TipContext $context): array
    {
        $key = $context->user->id.':'.$context->vendor->id;

        return $this->roleNames[$key] ??= DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $context->user->id)
            ->where('model_has_roles.model_type', $context->user->getMorphClass())
            ->where('model_has_roles.team_id', $context->vendor->id)
            ->pluck('roles.name')
            ->all();
    }

    /**
     * Every event this person has on these tips at this vendor, in one query.
     *
     * @param  Collection<int, Tip>  $tips
     * @return array<int, array<string, array{count: int, last: CarbonImmutable}>>
     */
    private function stateFor(TipContext $context, Collection $tips): array
    {
        $rows = TipEvent::query()
            ->where('user_id', $context->user->id)
            ->when(
                $context->vendor,
                fn ($q) => $q->where('vendor_id', $context->vendor->id),
                fn ($q) => $q->whereNull('vendor_id'),
            )
            ->whereIn('tip_id', $tips->map(fn (Tip $tip) => $tip->getKey())->all())
            ->groupBy('tip_id', 'event')
            ->selectRaw('tip_id, event, COUNT(*) as aggregate, MAX(created_at) as last_at')
            ->toBase()
            ->get();

        $state = [];

        foreach ($rows as $row) {
            $state[(int) $row->tip_id][$row->event] = [
                'count' => (int) $row->aggregate,
                'last' => CarbonImmutable::parse($row->last_at, config('app.timezone', 'UTC')),
            ];
        }

        return $state;
    }

    /**
     * Pending moments, plus any page-visit moment this render fires. A fired
     * page-visit moment is stored like the others, so it survives the trip
     * from the page to the lazily loaded tip component.
     *
     * @return array<string, int>
     */
    private function moments(TipContext $context): array
    {
        if (! $context->vendor) {
            return [];
        }

        foreach ($this->features->moments() as $moment) {
            $value = $moment->detectOnRender($context);

            if ($value !== null) {
                $this->pendingMoments->record($context->user->id, $context->vendor->id, $moment->key(), $value, $moment->expiresAt());
            }
        }

        return $this->pendingMoments->active($context->user->id, $context->vendor->id);
    }

    /** @param  array<string, array{count: int, last: CarbonImmutable}>  $state */
    private function lastSeenAt(array $state): ?CarbonImmutable
    {
        $times = array_filter([
            $state[TipEvent::SHOWN]['last'] ?? null,
            $state[TipEvent::SNOOZED]['last'] ?? null,
        ]);

        return $times === [] ? null : max($times);
    }

    /** Sort key: never seen sorts first, then oldest sighting. */
    private function lastSeen(array $state): int
    {
        return $this->lastSeenAt($state)?->getTimestamp() ?? 0;
    }
}
