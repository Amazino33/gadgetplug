<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who is asking, for which business, and what they may see.
 *
 * The one place the assistant decides store access and permissions. Before
 * this, "the stores of every vendor this user owns" was repeated in nine
 * places, which meant staff got nothing and an owner of two vendors was
 * answered from both at once whichever panel they were in.
 *
 * With a vendor (the Filament tenant the chat bubble is open in):
 *   - only that vendor's stores, and only the ones ActiveStore::accessibleFor()
 *     gives this user — the same rule every inventory screen uses;
 *   - permissions are that vendor's Spatie team permissions; the owner and
 *     super admins hold all of them.
 *
 * Without a vendor (direct calls with no tenant, e.g. older tests or a CLI
 * that has not said which business): stores of vendors the user owns, as
 * before. That fallback is never wider than the old behaviour.
 *
 * A scope is set for the duration of one question with run(), so the queries
 * — which are resolved from the container and called by the model — read the
 * same scope the request was authorised for, not whatever auth() says later.
 */
final class AssistantScope
{
    private static ?self $current = null;

    /** @var array<string, bool> */
    private array $permissionCache = [];

    /** @var Collection<int, Store>|null */
    private ?Collection $stores = null;

    private function __construct(
        public readonly User $user,
        public readonly ?Vendor $vendor,
    ) {}

    public static function for(User $user, ?Vendor $vendor = null): self
    {
        return new self($user, $vendor);
    }

    /** The scope of the question being answered right now, if any. */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Run $callback with this scope as current(), restoring the previous one
     * afterwards even if the callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $previous = self::$current;
        self::$current = $this;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public function isOwner(): bool
    {
        if ($this->user->isSuperAdmin()) {
            return true;
        }

        return $this->vendor
            ? $this->vendor->isOwner($this->user)
            : $this->user->ownedVendors()->exists();
    }

    /**
     * A vendor permission. Owner and super admin hold everything, exactly as
     * User::hasVendorPermission() and the panel's canAccess() checks treat
     * them. Without a vendor, only owners pass: nothing to check a team
     * permission against.
     */
    public function can(string $permission): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        if (! $this->vendor) {
            return false;
        }

        return $this->permissionCache[$permission]
            ??= $this->user->hasVendorPermission($this->vendor->id, $permission);
    }

    /** Cost, profit and margin — the same rule as ProductForm::canSeeCostPrice(). */
    public function canSeeCost(): bool
    {
        return $this->can('view_cost_price');
    }

    /**
     * The stores this user may be answered about.
     *
     * @return Collection<int, Store>
     */
    public function stores(): Collection
    {
        if ($this->stores !== null) {
            return $this->stores;
        }

        if ($this->vendor) {
            // A member who has lost access to the vendor gets no stores, even
            // if their store_user rows are still there.
            $member = $this->user->isSuperAdmin() || $this->user->canAccessTenant($this->vendor);

            return $this->stores = $member
                ? ActiveStore::accessibleFor($this->vendor, $this->user)->values()
                : collect();
        }

        // Listed stores stay "the ones you own", super admins included — the
        // list goes into the prompt, and every store on the platform would
        // be both wrong and huge. allows() still lets a super admin through.
        return $this->stores = Store::query()
            ->whereHas('vendor', fn (Builder $v) => $v->where('user_id', $this->user->id))
            ->get();
    }

    /** @return array<int, int> */
    public function storeIds(): array
    {
        return $this->stores()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function allows(int $storeId): bool
    {
        // Super admins without a vendor may query any store, as before.
        if (! $this->vendor && $this->user->isSuperAdmin()) {
            return true;
        }

        return in_array($storeId, $this->storeIds(), true);
    }

    /** @return array<int, string> */
    public function storeNames(): array
    {
        return $this->stores()->pluck('name')->map(fn ($n) => (string) $n)->values()->all();
    }
}
