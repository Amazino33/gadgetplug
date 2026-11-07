<?php

namespace App\Services\Assistant;

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who is asking, for which business, and what they may see.
 *
 * Built by the server from the authenticated user (or, in Phase 12, a
 * verified WhatsApp link) — never from anything the model says. Every tool
 * receives this and nothing else about identity, so there is no input a model
 * could fill in to reach another vendor: the vendor is not a parameter.
 *
 * Permission answers are memoised for the life of one question. A question
 * is a few seconds long; a revocation lands on the next one.
 */
final class AssistantContext
{
    /** @var array<string, bool> */
    private array $permissionCache = [];

    /** @var Collection<int, Store>|null */
    private ?Collection $stores = null;

    public function __construct(
        public readonly Vendor $vendor,
        public readonly User $user,
        public readonly string $channel = 'panel',
    ) {}

    public static function for(Vendor $vendor, User $user, string $channel = 'panel'): self
    {
        return new self($vendor, $user, $channel);
    }

    public function isOwner(): bool
    {
        return $this->user->isSuperAdmin() || $this->vendor->isOwner($this->user);
    }

    /**
     * The single permission check every tool goes through. Owner and super
     * admin hold everything, exactly as hasVendorPermission() and the panel's
     * own canAccess() checks treat them.
     */
    public function can(string $permission): bool
    {
        if ($this->isOwner()) {
            return true;
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
     * The stores this user may be told about, from the one access rule the
     * panel itself uses. Naming any other store — this vendor's or another's —
     * gets the same "no access" answer.
     *
     * @return Collection<int, Store>
     */
    public function stores(): Collection
    {
        return $this->stores ??= ActiveStore::accessibleFor($this->vendor, $this->user)->values();
    }

    /** @return array<int, int> */
    public function storeIds(): array
    {
        return $this->stores()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Whether the user can see every store the vendor has — the condition for
     * calling a combined figure "the whole business".
     */
    public function seesWholeBusiness(): bool
    {
        $all = Store::where('vendor_id', $this->vendor->id)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $mine = collect($this->storeIds())->sort()->values()->all();

        return $all !== [] && $all === $mine;
    }

    public function timezone(): string
    {
        return (string) config('reporting.timezone', 'Africa/Lagos');
    }

    /** "Now" on the business's wall clock. "Today" means the vendor's today. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }
}
