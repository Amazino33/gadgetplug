<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Actions\Inventory\AdjustStockAction;
use App\Services\ActiveStore;
use App\Models\AuditSession;
use App\Models\BlindCountAuthorization;
use App\Models\BlindCountEntry;
use App\Models\BlindCountSession;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Services\Pickings\PickingLedger;
use App\Models\User;
use App\Services\ShortageCaseService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use BackedEnum;
use UnitEnum;

class BlindCount extends Page
{
    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-eye-slash';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Inventory Count';
    protected static ?string $title           = 'Inventory Count';
    protected static ?int $navigationSort = 2;
    protected string  $view = 'filament.vendor.pages.blind-count';

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_inventory');
    }

    // Who physically records a count, as opposed to merely viewing the page
    // (canAccess). Deliberately permission-driven rather than hardcoded to the
    // 'storekeeper' role name, so any role can be made a counter from the Roles
    // screen. The owner is still excluded on purpose: whoever reviews and
    // resolves discrepancies shouldn't also be the one recording the counts.
    public function canCount(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        if ($vendor->isOwner($user)) return false;
        return $user->hasVendorPermission($vendor->id, 'perform_inventory_count');
    }

    public function canReset(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $user->isSuperAdmin() || $user->hasVendorPermission($vendor->id, 'edit_products');
    }

    // Who may let someone count again before the vendor's cadence has elapsed.
    // Deliberately a separate permission from perform_inventory_count: a counter
    // must never be able to authorise their own re-count, which is the whole
    // point of having a cadence at all.
    public function canAuthorizeRecount(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'authorize_recount');
    }

    // ── Livewire state ────────────────────────────────────────────────────────
    public ?int    $sessionId       = null;
    public bool    $byCategory      = false;

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function getSession(): ?BlindCountSession
    {
        return $this->sessionId ? BlindCountSession::find($this->sessionId) : null;
    }

    /**
     * Every product this session covers, preloaded once with everything the
     * counting screen needs to walk the whole list without another round trip:
     * identity, image, and how much is out with a picker. This is what makes
     * the rest of the count — navigating, typing, marking not-found, jumping to
     * a scanned barcode — a purely client-side affair instead of one Livewire
     * call per step, which is what made the screen crawl on a bad connection.
     *
     * @return array<int, array{id: int, name: string, sku: ?string, barcode: ?string, image: ?string, out_on_picking: int}>
     */
    public function productsForCounting(): array
    {
        $session = $this->getSession();
        if (! $session) return [];

        $productIds = $session->product_order;

        $products = Product::with('media')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        // Units held by a picker are already something the system knows about,
        // never something the counter is being asked to notice a discrepancy
        // for. Batched once for the whole session — the whole point of this
        // payload is that nothing here costs a query per product.
        $outOnPicking = PickingLedger::heldQuantitiesForProducts($productIds, $session->store_id);

        return collect($productIds)
            ->map(function (int $id) use ($products, $outOnPicking) {
                $product = $products->get($id);

                if (! $product) return null;

                return [
                    'id'             => $product->id,
                    'name'           => $product->name,
                    'sku'            => $product->sku,
                    'barcode'        => $product->barcode,
                    // No stock/reorder signal here — a blind count must never
                    // see system stock state while counting.
                    'image'          => $product->getFirstMediaUrl('product-images', 'preview') ?: null,
                    'out_on_picking' => $outOnPicking[$id] ?? 0,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function getRole(): string
    {
        $session = $this->getSession();
        if (! $session) return 'none';
        if ($session->storekeeper_a_id === auth()->id()) return 'a';
        if ($session->storekeeper_b_id === auth()->id()) return 'b';
        return 'observer';
    }

    public function getTotalProducts(): int
    {
        return count($this->getSession()?->product_order ?? []);
    }

    // Only the two counters may write entries — an observer must never be able to
    // record a count, even by calling a Livewire action directly.
    private function isParticipant(): bool
    {
        return in_array($this->getRole(), ['a', 'b'], true);
    }

    // ── Mount ─────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $vendor  = filament()->getTenant();
        $session = BlindCountSession::where('vendor_id', $vendor->id)
            ->whereIn('status', ['a_counting', 'b_counting'])
            ->latest()
            ->first();

        if ($session) {
            $this->sessionId = $session->id;
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────
    public function startSession(): void
    {
        if (! $this->canCount()) {
            Notification::make()->title('Only storekeepers can start an inventory count session.')->warning()->send();
            return;
        }

        $vendor = filament()->getTenant();

        if (BlindCountSession::isBlockedFor(auth()->id(), $vendor)) {
            Notification::make()
                ->title('Next count not due yet')
                ->body($this->blockedMessage())
                ->warning()
                ->send();
            return;
        }

        // A count is taken at one branch, so it walks that branch's shelves.
        $storeId = ActiveStore::currentId();
        $productIds = $this->buildProductOrder($vendor->id, $storeId);

        if (empty($productIds)) {
            Notification::make()->title('No published products found to count.')->warning()->send();
            return;
        }

        // Only burn the manager's authorisation once a session really starts
        $this->consumeAuthorization($vendor);

        $session = BlindCountSession::create([
            'vendor_id'        => $vendor->id,
            'store_id'         => $storeId,
            'storekeeper_a_id' => auth()->id(),
            'status'           => 'a_counting',
            'frequency'        => $vendor->pos_blind_count_frequency ?? 'daily',
            'custom_days'      => $vendor->pos_blind_count_custom_days,
            'by_category'      => $this->byCategory,
            'product_order'    => $productIds,
        ]);

        $this->sessionId = $session->id;
    }

    // ── Cadence / re-count authorisation ──────────────────────────────────────

    public function nextCountDue(): ?\Carbon\CarbonInterface
    {
        return BlindCountSession::nextCountDueFor(auth()->id(), filament()->getTenant());
    }

    public function isBlockedByCadence(): bool
    {
        return BlindCountSession::isBlockedFor(auth()->id(), filament()->getTenant());
    }

    public function hasRecountAuthorization(): bool
    {
        return BlindCountAuthorization::unusedFor(auth()->id(), filament()->getTenant()->id) !== null;
    }

    private function blockedMessage(): string
    {
        $due = $this->nextCountDue();

        return $due
            ? "You counted recently. Your next count is due {$due->format('j M Y, g:ia')}. A manager can authorise an earlier count."
            : 'A manager can authorise an earlier count.';
    }

    private function consumeAuthorization(\App\Models\Vendor $vendor): void
    {
        BlindCountAuthorization::unusedFor(auth()->id(), $vendor->id)?->update(['used_at' => now()]);
    }

    // Counters currently held back by the cadence, for the manager's view
    public function getBlockedCounters(): \Illuminate\Support\Collection
    {
        if (! $this->canAuthorizeRecount()) return collect();

        $vendor = filament()->getTenant();

        return $vendor->users
            ->filter(fn (User $u) => BlindCountSession::nextCountDueFor($u->id, $vendor) !== null)
            ->map(fn (User $u) => (object) [
                'user'       => $u,
                'due'        => BlindCountSession::nextCountDueFor($u->id, $vendor),
                'authorized' => BlindCountAuthorization::unusedFor($u->id, $vendor->id) !== null,
            ])
            ->values();
    }

    public function authorizeRecount(int $userId): void
    {
        if (! $this->canAuthorizeRecount()) {
            Notification::make()->title('You are not allowed to authorise a re-count.')->danger()->send();
            return;
        }

        $vendor = filament()->getTenant();

        // A counter authorising themselves would defeat the cadence entirely
        if ($userId === auth()->id()) {
            Notification::make()->title('You cannot authorise your own re-count.')->danger()->send();
            return;
        }

        if (! $vendor->users->contains('id', $userId)) {
            Notification::make()->title('That user is not part of this store.')->danger()->send();
            return;
        }

        if (BlindCountAuthorization::unusedFor($userId, $vendor->id)) {
            Notification::make()->title('That counter is already authorised.')->warning()->send();
            return;
        }

        BlindCountAuthorization::create([
            'vendor_id'     => $vendor->id,
            'user_id'       => $userId,
            'granted_by_id' => auth()->id(),
        ]);

        $user = User::find($userId);

        Notification::make()
            ->title("{$user->name} can now start an early count.")
            ->body('The authorisation is used up as soon as they begin.')
            ->success()
            ->send();
    }

    // What the system believes is on this branch's shelf — the figure the
    // count is measured against. For a store-scoped session that is the
    // store's own row; comparing against products.stock_quantity would measure
    // one branch's count against every branch's stock and report the rest of
    // the business as missing. Sessions from before stores fall back to the
    // vendor-wide mirror, which is what they were counted against.
    private function systemQuantityFor(BlindCountSession $session, Product $product): int
    {
        if ($session->store_id === null) {
            return (int) $product->stock_quantity;
        }

        return (int) (ProductStoreStock::where('product_id', $product->id)
            ->where('store_id', $session->store_id)
            ->value('quantity') ?? 0);
    }

    private function buildProductOrder(int $vendorId, ?int $storeId = null): array
    {
        $products = Product::published()
            ->where('vendor_id', $vendorId)
            // Only the products homed at this branch. Without this a
            // storekeeper at a three-product branch would be walked through the
            // vendor's entire catalogue, counting zero over and over — and a
            // count of zero against another branch's stock would then be
            // reconciled as a shortage.
            //
            // Home store rather than "holds a row here": a product that has
            // sold out still belongs on this branch's count sheet. Counting it
            // and finding nothing is a real answer; skipping it hides whether
            // the shelf is genuinely empty or the stock walked.
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->get(['id', 'category_id']);

        if ($this->byCategory) {
            return $products
                ->groupBy('category_id')
                ->shuffle()
                ->flatMap(fn ($group) => $group->shuffle()->pluck('id'))
                ->values()
                ->toArray();
        }

        return $products->shuffle()->pluck('id')->toArray();
    }

    public function joinAsB(): void
    {
        if (! $this->canCount()) {
            Notification::make()->title('Only storekeepers can participate in inventory counts.')->warning()->send();
            return;
        }

        $session = $this->getSession();
        if (! $session || $session->status !== 'b_counting') return;

        if ($session->storekeeper_a_id === auth()->id()) {
            Notification::make()->title('You cannot verify your own count.')->danger()->send();
            return;
        }

        $vendor = filament()->getTenant();

        if (BlindCountSession::isBlockedFor(auth()->id(), $vendor)) {
            Notification::make()
                ->title('Next count not due yet')
                ->body($this->blockedMessage())
                ->warning()
                ->send();
            return;
        }

        $this->consumeAuthorization($vendor);

        $session->update(['storekeeper_b_id' => auth()->id()]);
    }

    // The counting screen runs full-screen with the panel chrome hidden, so
    // there is no nav to leave by — exiting has to be an explicit action.
    // Nothing to save here any more: every entry lives in the browser (and its
    // localStorage draft) until finishCounting() ships the whole thing in one
    // request, so leaving mid-count risks nothing server-side. The session
    // itself stays open; BlindCountInProgressWidget leads back to it, and the
    // draft picks up where it left off on the same device.
    public function exitCount(): void
    {
        $this->redirect(filament()->getPanel('vendor')->getUrl(filament()->getTenant()));
    }

    /**
     * Writes every counted entry in one transaction, then runs exactly the
     * same finishing logic submitAll() always has. Split from submitAll()
     * rather than folded into it: submitAll() alone is still how a session
     * whose entries already exist in the database gets finished (tests, and
     * anything that writes BlindCountEntry rows directly), while this is the
     * one new call the counting screen itself makes, with everything the
     * counter entered while offline-tolerant of the network in one shot.
     *
     * @param  array<int, array{count?: int|string|null, note?: ?string}>  $entries  product id => what was counted
     */
    public function finishCounting(array $entries): void
    {
        $this->saveAllEntries($entries);
        $this->submitAll();
    }

    private function saveAllEntries(array $entries): void
    {
        if (! $this->isParticipant()) return;

        $session = $this->getSession();
        if (! $session) return;

        $now  = now();
        $rows = [];

        foreach ($session->product_order as $index => $productId) {
            $entry = $entries[$productId] ?? null;
            $count = $entry['count'] ?? null;

            // A product the client never sent anything for is left out rather
            // than defaulted to zero here — submitAll()'s completeness check
            // below is what decides whether that blocks finishing, the same
            // guard it has always enforced.
            if ($count === null || $count === '') continue;

            $note = ! empty($entry['note']) ? (string) $entry['note'] : null;

            $rows[] = [
                'blind_count_session_id' => $session->id,
                'user_id'                => auth()->id(),
                'product_id'             => $productId,
                'position'               => $index + 1,
                'count'                  => (int) $count,
                'note'                   => $note,
                'counted_at'             => $now,
                'created_at'             => $now,
                'updated_at'             => $now,
            ];
        }

        if ($rows === []) return;

        BlindCountEntry::upsert(
            $rows,
            ['blind_count_session_id', 'user_id', 'product_id'],
            ['position', 'count', 'note', 'counted_at', 'updated_at']
        );
    }

    public function submitAll(): void
    {
        if (! $this->isParticipant()) return;

        $session = $this->getSession();
        if (! $session) return;

        $counted = BlindCountEntry::where('blind_count_session_id', $session->id)
            ->where('user_id', auth()->id())
            ->whereNotNull('count')
            ->count();

        if ($counted < count($session->product_order)) {
            Notification::make()
                ->title('Incomplete count')
                ->body('All products must be counted before submitting.')
                ->warning()
                ->send();
            return;
        }

        $role        = $this->getRole();
        $vendor      = filament()->getTenant();
        $singlePerson = ($vendor->pos_blind_count_participants ?? 2) === 1;

        if ($role === 'a') {
            if ($singlePerson) {
                // Single-person mode: complete immediately without waiting for a second counter
                $session->update([
                    'status'          => 'completed',
                    'a_submitted_at'  => now(),
                    'b_submitted_at'  => now(),
                ]);

                try {
                    $discrepancies = $this->processComparisonSinglePerson($session->fresh());
                    $message = $discrepancies > 0
                        ? "Count complete. {$discrepancies} discrepanc" . ($discrepancies === 1 ? 'y' : 'ies') . " flagged for manager review."
                        : 'Count complete. All stock levels verified.';
                    Notification::make()->title($message)->success()->send();
                } catch (\Throwable $e) {
                    Log::error('BlindCount single-person comparison failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);
                    Notification::make()
                        ->title('Comparison failed')
                        ->body('Your count was saved but stock records could not be updated. Error: ' . $e->getMessage())
                        ->danger()
                        ->send();
                }
            } else {
                $session->update(['status' => 'b_counting', 'a_submitted_at' => now()]);
                Notification::make()->title('Count submitted. Waiting for Storekeeper B.')->success()->send();
            }
        } elseif ($role === 'b') {
            $session->update(['status' => 'completed', 'b_submitted_at' => now()]);

            try {
                $discrepancies = $this->processComparison($session->fresh());
                $message = $discrepancies > 0
                    ? "Count complete. {$discrepancies} discrepanc" . ($discrepancies === 1 ? 'y' : 'ies') . " flagged for manager review."
                    : 'Count complete. All stock levels verified and updated.';
                Notification::make()->title($message)->success()->send();
            } catch (\Throwable $e) {
                Log::error('BlindCount processComparison failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);
                Notification::make()
                    ->title('Comparison failed')
                    ->body('Your count was saved but the audit records could not be created. Contact your administrator. Error: ' . $e->getMessage())
                    ->danger()
                    ->send();
            }
        }
    }

    // resetSession() clears the counts but keeps the session bound to whoever
    // started it, so it never frees the store for a different counter. Cancelling
    // discards the session entirely, which is what you want when the wrong person
    // opened it or a count was abandoned part-way.
    public function canCancel(): bool
    {
        $session = $this->getSession();

        // A completed session is an audit record — it must never be deletable
        if (! $session || $session->status === 'completed') return false;

        if ($this->canReset()) return true;

        // A counter may abandon a session only while it is actually their turn,
        // so nobody can wipe out a colleague's submitted count mid-verification.
        return ($session->status === 'a_counting' && $this->getRole() === 'a')
            || ($session->status === 'b_counting' && $this->getRole() === 'b');
    }

    public function cancelSession(): void
    {
        if (! $this->canCancel()) {
            Notification::make()->title('You cannot cancel this count session.')->danger()->send();
            return;
        }

        $session = $this->getSession();
        if (! $session) return;

        BlindCountEntry::where('blind_count_session_id', $session->id)->delete();
        $session->delete();

        // Back to a clean slate so the page re-renders on the start screen
        $this->sessionId = null;

        Notification::make()
            ->title('Count session cancelled')
            ->body('Nothing was saved to stock. Anyone eligible can now start a fresh count.')
            ->success()
            ->send();
    }

    public function resetSession(): void
    {
        if (! $this->canReset()) {
            Notification::make()->title('Only owners and inventory managers can reset a count session.')->danger()->send();
            return;
        }

        $session = $this->getSession();
        if (! $session) return;

        BlindCountEntry::where('blind_count_session_id', $session->id)->delete();

        $session->update([
            'status'           => 'a_counting',
            'storekeeper_b_id' => null,
            'a_submitted_at'   => null,
            'b_submitted_at'   => null,
        ]);

        Notification::make()->title('Session reset. Storekeeper A can start their count over.')->success()->send();
    }

    // Solo counts get the same scrutiny as dual counts: any variance — over or
    // under — is left as a 'discrepancy' for manager review rather than being
    // auto-applied to stock. Only an exact match auto-verifies.
    private function processComparisonSinglePerson(BlindCountSession $session): int
    {
        $entries = BlindCountEntry::where('blind_count_session_id', $session->id)
            ->where('user_id', $session->storekeeper_a_id)
            ->get()->keyBy('product_id');

        $discrepancies = 0;

        DB::transaction(function () use ($session, $entries, &$discrepancies) {
            foreach ($session->product_order as $productId) {
                $count      = (int) ($entries[$productId]?->count ?? 0);
                $product    = Product::find($productId);
                $difference = $count - $this->systemQuantityFor($session, $product);
                $matched    = $difference === 0;

                $line = AuditSession::create([
                    'vendor_id'        => $session->vendor_id,
                    // Which count produced this line — without it the sessions
                    // list has no way to group its own lines.
                    'blind_count_session_id' => $session->id,
                    'product_id'       => $productId,
                    // The baseline this count is measured against, frozen now.
                    // Read live afterwards it would drift with every sale.
                    'system_quantity'  => $this->systemQuantityFor($session, $product),
                    'storekeeper_a_id' => $session->storekeeper_a_id,
                    'storekeeper_b_id' => null,
                    'count_a'          => $count,
                    'count_b'          => null,
                    'status'           => $matched ? 'verified' : 'discrepancy',
                ]);

                if (! $matched) {
                    // A balanced line opens nothing — the service returns null
                    // for zero variance, so the real cases are not buried in
                    // noise. Opening the case is deferred from the stock fix on
                    // purpose: correcting the shelf figure must never wait on a
                    // decision about a person.
                    app(ShortageCaseService::class)->openForCountLine($line);
                    $discrepancies++;
                }
            }
        });

        $this->notifyManagersOfDiscrepancies($session->vendor_id, $discrepancies);

        return $discrepancies;
    }

    private function notifyManagersOfDiscrepancies(int $vendorId, int $discrepancies): void
    {
        if ($discrepancies <= 0) return;

        try {
            $managers = User::where(fn ($q) => $q
                    ->whereHas('ownedVendors', fn ($q) => $q->where('id', $vendorId))
                    ->orWhereHas('roles', fn ($q) => $q
                        ->where('name', 'inventory_manager')
                        ->where('team_id', $vendorId)
                    )
                )
                ->where('id', '!=', auth()->id())
                ->get();

            if ($managers->isNotEmpty()) {
                Notification::make()
                    ->title("{$discrepancies} discrepanc" . ($discrepancies === 1 ? 'y' : 'ies') . " found in inventory count")
                    ->body('Review the Audit Sessions page to resolve them.')
                    ->danger()
                    ->sendToDatabase($managers);
            }
        } catch (\Throwable $e) {
            Log::warning('BlindCount manager notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function processComparison(BlindCountSession $session): int
    {
        $aEntries = BlindCountEntry::where('blind_count_session_id', $session->id)
            ->where('user_id', $session->storekeeper_a_id)
            ->get()->keyBy('product_id');

        $bEntries = BlindCountEntry::where('blind_count_session_id', $session->id)
            ->where('user_id', $session->storekeeper_b_id)
            ->get()->keyBy('product_id');

        $adjustStock   = app(AdjustStockAction::class);
        $discrepancies = 0;

        // Wrap in a transaction so all AuditSessions are created or none are
        DB::transaction(function () use ($session, $aEntries, $bEntries, $adjustStock, &$discrepancies) {
            foreach ($session->product_order as $productId) {
                $countA  = (int) ($aEntries[$productId]?->count ?? 0);
                $countB  = (int) ($bEntries[$productId]?->count ?? 0);
                $matched = $countA === $countB;
                $product = Product::find($productId);

                $line = AuditSession::create([
                    'vendor_id'        => $session->vendor_id,
                    // Which count produced this line — without it the sessions
                    // list has no way to group its own lines.
                    'blind_count_session_id' => $session->id,
                    'product_id'       => $productId,
                    // Frozen baseline — see the solo path above.
                    'system_quantity'  => $this->systemQuantityFor($session, $product),
                    'storekeeper_a_id' => $session->storekeeper_a_id,
                    'storekeeper_b_id' => $session->storekeeper_b_id,
                    'count_a'          => $countA,
                    'count_b'          => $countB,
                    'status'           => $matched ? 'verified' : 'discrepancy',
                ]);

                if ($matched) {
                    $systemQuantity = $this->systemQuantityFor($session, $product);
                    $difference = $countB - $systemQuantity;

                    if ($difference !== 0) {
                        $adjustStock->execute(
                            productId:       $productId,
                            quantityChanged: $difference,
                            transactionType: 'audit_correction',
                            userId:          $session->storekeeper_b_id,
                            reference:       "Inventory Count #{$session->id}",
                            description:     "Verified count. System had {$systemQuantity}, found {$countB}.",
                            // The branch this session counted, read from the
                            // session itself rather than from whoever happens
                            // to be looking at the page when it reconciles.
                            store:           $session->store_id,
                        );
                    }
                } else {
                    $discrepancies++;
                }

                // Outside the matched branch on purpose. Two counters agreeing is
                // not the same as nothing being missing: they can agree on 7
                // against a baseline of 10, which is a settled, accepted
                // three-unit loss and exactly the case worth opening. The service
                // returns null when the variance really is zero.
                app(ShortageCaseService::class)->openForCountLine($line);
            }
        });

        // Notify managers — runs outside the transaction so a notification failure
        // never rolls back the audit records that were just created
        $this->notifyManagersOfDiscrepancies($session->vendor_id, $discrepancies);

        return $discrepancies;
    }
}
