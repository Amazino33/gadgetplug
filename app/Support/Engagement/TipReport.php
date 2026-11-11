<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The per-tip report on the admin tip record: the funnel from TipAdoption,
 * the thumbs and plays from TipFeedback, and one readable row per person.
 *
 * Statuses are TipAdoption's, never recomputed here; this only adds the
 * names an admin needs to read the list (business, branch, person, role).
 */
class TipReport
{
    public const STATUS_LABELS = [
        TipAdoption::SAW => 'Saw only',
        TipAdoption::TAPPED => 'Tapped, not used',
        TipAdoption::USED => 'Used it',
        TipAdoption::ALREADY_USING => 'Already using',
    ];

    public function __construct(private readonly TipAdoption $adoption) {}

    /**
     * @return array{saw: int, tapped: int, used: int, already_using: int, yes: int, no: int, percent: int|null, plays: int, has_feature: bool}
     */
    public function summary(Tip $tip): array
    {
        return [
            ...$this->adoption->summary($tip),
            ...TipFeedback::forTip($tip->id),
            'has_feature' => filled($tip->feature_key),
        ];
    }

    /**
     * One row per person per business who has been shown the tip, newest
     * sighting first.
     *
     * @return Collection<int, array{key: string, vendor: string, store: string|null, user: string, role: string, status: string, status_label: string, first_shown_at: mixed, clicked_at: mixed, first_used_at: mixed, scope: string}>
     */
    public function rows(Tip $tip): Collection
    {
        $rows = $this->adoption->rows($tip);

        if ($rows->isEmpty()) {
            return collect();
        }

        $userIds = $rows->pluck('user_id')->unique()->all();
        $vendorIds = $rows->pluck('vendor_id')->filter()->unique()->all();

        $users = User::query()->whereIn('id', $userIds)->pluck('name', 'id');
        $vendors = DB::table('vendors')->whereIn('id', $vendorIds)->get(['id', 'name', 'user_id'])->keyBy('id');
        $stores = $this->firstStores($tip);
        $storeNames = DB::table('stores')->whereIn('id', $stores->filter()->unique()->all())->pluck('name', 'id');
        $roles = $this->roleNames($userIds, $vendorIds);

        return $rows->map(function (array $row) use ($users, $vendors, $stores, $storeNames, $roles): array {
            $key = $row['user_id'].':'.($row['vendor_id'] ?? '');
            $vendor = $row['vendor_id'] !== null ? $vendors->get($row['vendor_id']) : null;
            $storeId = $stores->get($key);
            $isOwner = $vendor !== null && (int) $vendor->user_id === $row['user_id'];
            $roleNames = $roles[$key] ?? [];

            return [
                'key' => $key,
                'vendor' => $vendor?->name ?? '–',
                'store' => $storeId ? ($storeNames[$storeId] ?? null) : null,
                'user' => (string) ($users[$row['user_id']] ?? 'Deleted user'),
                'role' => $isOwner ? 'Owner' : ($roleNames !== [] ? implode(', ', array_map(fn ($r) => ucwords(str_replace('_', ' ', $r)), $roleNames)) : '–'),
                'status' => $row['status'],
                'status_label' => self::STATUS_LABELS[$row['status']],
                'first_shown_at' => $row['first_shown_at'],
                'clicked_at' => $row['clicked_at'],
                'first_used_at' => $row['first_used_at'],
                // Honest labelling: whose use the "used" date is.
                'scope' => $row['business_wide'] ? 'Business' : 'This person',
            ];
        })
            ->sortByDesc(fn (array $row) => $row['first_shown_at']->getTimestamp())
            ->values();
    }

    /** The same rows, filtered the way the report's table filters them. */
    public function filtered(Tip $tip, ?string $status, ?string $search): Collection
    {
        $search = mb_strtolower(trim((string) $search));

        return $this->rows($tip)
            ->when(filled($status), fn (Collection $rows) => $rows->where('status', $status))
            ->when($search !== '', fn (Collection $rows) => $rows->filter(
                fn (array $row) => str_contains(mb_strtolower($row['vendor'].' '.$row['user'].' '.$row['store']), $search),
            ))
            ->values();
    }

    /** The CSV download of the given rows. Times are Lagos time. */
    public static function csv(Collection $rows): string
    {
        $at = fn ($time) => $time?->setTimezone('Africa/Lagos')->format('Y-m-d H:i') ?? '';

        return Csv::build(
            ['Business', 'Branch', 'Person', 'Role', 'Status', 'First saw', 'Tapped', 'First used', 'Used counted for'],
            $rows->map(fn (array $row) => [
                $row['vendor'], $row['store'], $row['user'], $row['role'], $row['status_label'],
                $at($row['first_shown_at']), $at($row['clicked_at']), $at($row['first_used_at']), $row['scope'],
            ]),
        );
    }

    /**
     * The branch each person was in when they first saw the tip.
     *
     * @return Collection<string, int|null>
     */
    private function firstStores(Tip $tip): Collection
    {
        return TipEvent::query()
            ->where('tip_id', $tip->id)
            ->where('event', TipEvent::SHOWN)
            ->orderBy('id')
            ->get(['user_id', 'vendor_id', 'store_id'])
            ->unique(fn (TipEvent $e) => $e->user_id.':'.($e->vendor_id ?? ''))
            ->mapWithKeys(fn (TipEvent $e) => [$e->user_id.':'.($e->vendor_id ?? '') => $e->store_id ? (int) $e->store_id : null]);
    }

    /**
     * Role names per person per business, read from the pivot at that
     * business's team.
     *
     * @return array<string, list<string>>
     */
    private function roleNames(array $userIds, array $vendorIds): array
    {
        if ($vendorIds === []) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('model_has_roles.model_id', $userIds)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.team_id', $vendorIds)
            ->get(['model_has_roles.model_id', 'model_has_roles.team_id', 'roles.name'])
            ->groupBy(fn ($r) => $r->model_id.':'.$r->team_id)
            ->map(fn ($group) => $group->pluck('name')->all())
            ->all();
    }
}
