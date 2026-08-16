<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Gives every existing vendor the one store it has always implicitly been, and
// moves every existing team member onto it. Until this runs, a vendor's stock
// and staff belong to the vendor directly; after it, they belong to a store
// that belongs to the vendor — and no member may lose access in the crossing.
//
// Written against the query builder rather than the Store/User models on
// purpose: a migration that calls model code is a migration that breaks the
// day someone renames a relationship or adds a global scope. Slug generation
// is done here in full for the same reason, instead of leaning on the model's
// HasSlug trait.
//
// Idempotent end to end — every write is guarded by a lookup first, so a
// re-run (or a partial run resumed after failure) adds nothing twice.
return new class extends Migration
{
    private const DEFAULT_STORE_NAME = 'Main Store';

    public function up(): void
    {
        $now = now();

        DB::table('vendors')->orderBy('id')->select('id')->chunk(200, function ($vendors) use ($now) {
            foreach ($vendors as $vendor) {
                $storeId = $this->defaultStoreId($vendor->id)
                    ?? $this->createDefaultStore($vendor->id, $now);

                $this->linkMembers($vendor->id, $storeId, $now);
            }
        });

        $this->assertEveryVendorHasExactlyOneDefaultStore();
        $this->assertEveryMemberReachedTheDefaultStore();
    }

    // Deliberately empty. The tables this wrote into are dropped by the two
    // migrations above it, so there is nothing to undo; and if those tables
    // survive, deleting the rows would strip real people of store access that
    // may have been curated by hand since. A no-op is the honest reversal.
    public function down(): void
    {
        //
    }

    private function defaultStoreId(int $vendorId): ?int
    {
        $id = DB::table('stores')
            ->where('vendor_id', $vendorId)
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function createDefaultStore(int $vendorId, $now): int
    {
        return (int) DB::table('stores')->insertGetId([
            'vendor_id'  => $vendorId,
            'name'       => self::DEFAULT_STORE_NAME,
            'slug'       => $this->uniqueSlug($vendorId, self::DEFAULT_STORE_NAME),
            'is_default' => true,
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Only vendor_users rows are carried over. A vendor's owner is not
    // necessarily in that pivot — owner access is decided by vendors.user_id
    // through Vendor::isOwner()/canAccess(), and inventing a membership row for
    // them here would quietly change what that pivot means.
    private function linkMembers(int $vendorId, int $storeId, $now): void
    {
        $memberIds = DB::table('vendor_users')
            ->where('vendor_id', $vendorId)
            ->pluck('user_id');

        if ($memberIds->isEmpty()) {
            return;
        }

        $alreadyLinked = DB::table('store_user')
            ->where('store_id', $storeId)
            ->pluck('user_id');

        $rows = $memberIds->unique()
            ->diff($alreadyLinked)
            ->map(fn ($userId) => [
                'store_id'   => $storeId,
                'user_id'    => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('store_user')->insert($rows);
        }
    }

    private function uniqueSlug(int $vendorId, string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while (DB::table('stores')->where('vendor_id', $vendorId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function assertEveryVendorHasExactlyOneDefaultStore(): void
    {
        $defaultsByVendor = DB::table('stores')
            ->where('is_default', true)
            ->groupBy('vendor_id')
            ->select('vendor_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'vendor_id');

        $wrong = DB::table('vendors')->pluck('id')
            ->reject(fn ($vendorId) => (int) ($defaultsByVendor[$vendorId] ?? 0) === 1)
            ->values();

        if ($wrong->isNotEmpty()) {
            throw new RuntimeException(
                'Store backfill aborted: vendors without exactly one default store — ids '.$wrong->implode(', ')
            );
        }
    }

    private function assertEveryMemberReachedTheDefaultStore(): void
    {
        $orphans = DB::table('vendor_users as vu')
            ->join('stores as s', fn ($join) => $join
                ->on('s.vendor_id', '=', 'vu.vendor_id')
                ->where('s.is_default', '=', true))
            ->leftJoin('store_user as su', fn ($join) => $join
                ->on('su.store_id', '=', 's.id')
                ->on('su.user_id', '=', 'vu.user_id'))
            ->whereNull('su.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Store backfill aborted: {$orphans} vendor member(s) have no row on their vendor's default store."
            );
        }
    }
};
