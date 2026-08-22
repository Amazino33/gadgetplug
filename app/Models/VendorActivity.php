<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity;

/**
 * The app's activity model (config/activitylog.php points here), extended so
 * every row knows which vendor — and where possible which store — it belongs to.
 *
 * Scope is resolved HERE rather than at each call site. Relying on every
 * `activity()` chain to remember `->tap(fn ($a) => $a->vendor_id = ...)` is what
 * left FinancialLedger and VendorObserver writing rows with a null vendor_id,
 * invisible in the very feed they were meant to appear in. An explicit tap still
 * wins; this only fills what the caller left blank.
 */
class VendorActivity extends Activity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity) {
            $activity->vendor_id ??= $activity->resolveVendorId();
            $activity->store_id  ??= $activity->resolveStoreId();
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Cheapest reliable source first. The subject is the strongest signal —
     * it is the thing that actually changed — and only when it cannot answer
     * do we fall back to ambient context.
     */
    protected function resolveVendorId(): ?int
    {
        $subject = $this->subject;

        if ($subject) {
            if (isset($subject->vendor_id)) {
                return (int) $subject->vendor_id;
            }

            // A Vendor is its own scope.
            if ($subject instanceof Vendor) {
                return (int) $subject->id;
            }

            // One hop for models that reach the vendor through a parent
            // (an OrderItem through its order, say). Guarded because touching
            // a missing relation during a log write must never throw.
            $viaRelation = rescue(fn () => $subject->vendor?->id, null, false);

            if ($viaRelation) {
                return (int) $viaRelation;
            }
        }

        // Inside a vendor panel request the tenant is unambiguous.
        $tenant = rescue(fn () => filament()->getTenant(), null, false);

        if ($tenant instanceof Vendor) {
            return (int) $tenant->id;
        }

        // Console jobs and non-panel requests: fall back to the causer, but
        // only when they belong to exactly one vendor. Guessing between two
        // would file the row under the wrong store, which is worse than
        // leaving it unscoped.
        $causer = $this->causer;

        if ($causer instanceof User) {
            // vendors() returns a merged Collection of owned + member vendors,
            // not a relation — so it is plucked as a collection, not queried.
            $vendors = rescue(fn () => $causer->vendors(), collect(), false);

            if ($vendors->count() === 1) {
                return (int) $vendors->first()->id;
            }
        }

        return null;
    }

    protected function resolveStoreId(): ?int
    {
        $subject = $this->subject;

        if ($subject) {
            if (isset($subject->store_id)) {
                return (int) $subject->store_id;
            }

            if ($subject instanceof Store) {
                return (int) $subject->id;
            }
        }

        // Deliberately no ambient fallback. A vendor-level action genuinely has
        // no store, and inventing one from the causer's till would misfile
        // things like a price change as if it happened at one branch.
        return null;
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForStore(Builder $query, ?int $storeId): Builder
    {
        return $storeId === null ? $query : $query->where('store_id', $storeId);
    }
}
