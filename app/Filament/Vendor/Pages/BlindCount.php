<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\AuditSession;
use App\Models\BlindCountEntry;
use App\Models\BlindCountSession;
use App\Models\Product;
use App\Models\User;
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
    protected static ?int    $navigationSort  = 3;
    protected string  $view = 'filament.vendor.pages.blind-count';

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_inventory');
    }

    // Owners and inventory_managers observe; only storekeepers physically count
    public function canCount(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        if ($vendor->isOwner($user)) return false;
        return $user->hasVendorRole($vendor->id, ['storekeeper']);
    }

    public function canReset(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $user->isSuperAdmin() || $user->hasVendorPermission($vendor->id, 'edit_products');
    }

    // ── Livewire state ────────────────────────────────────────────────────────
    public ?int    $sessionId       = null;
    public int     $currentPosition = 1;
    public int     $count           = 0;
    public string  $note            = '';
    public bool    $showSearch      = false;
    public string  $searchQuery     = '';
    public string  $frequency       = 'daily';
    public bool    $byCategory      = false;
    public ?int    $customDays      = null;

    // One-shot undo snapshot — captures the previous value of whichever entry
    // was just overwritten by saveCurrentEntry(), so undoLast() can restore it.
    public bool    $canUndo           = false;
    public ?int    $undoPosition      = null;
    public ?int    $undoPreviousCount = null;
    public ?string $undoPreviousNote  = null;

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function getSession(): ?BlindCountSession
    {
        return $this->sessionId ? BlindCountSession::find($this->sessionId) : null;
    }

    public function getCurrentProduct(): ?Product
    {
        $session = $this->getSession();
        if (! $session) return null;

        $productId = $session->product_order[$this->currentPosition - 1] ?? null;
        if (! $productId) return null;

        return Product::with(['media', 'category'])->find($productId);
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

    public function getCountedEntries(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->sessionId) return collect();

        return BlindCountEntry::where('blind_count_session_id', $this->sessionId)
            ->where('user_id', auth()->id())
            ->whereNotNull('count')
            ->with('product')
            ->when($this->searchQuery, fn ($q) => $q->whereHas('product', fn ($pq) =>
                $pq->where('name', 'like', "%{$this->searchQuery}%")
                   ->orWhere('sku', 'like', "%{$this->searchQuery}%")
            ))
            ->orderBy('position')
            ->get();
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
            $this->restorePosition($session);
        }
    }

    private function restorePosition(BlindCountSession $session): void
    {
        $position              = $session->currentPositionFor(auth()->id());
        $this->currentPosition = max(1, min($position, count($session->product_order)));

        $productId = $session->product_order[$this->currentPosition - 1] ?? null;
        if ($productId) {
            $entry       = BlindCountEntry::where('blind_count_session_id', $session->id)
                ->where('user_id', auth()->id())
                ->where('product_id', $productId)
                ->first();
            $this->count = $entry?->count ?? 0;
            $this->note  = $entry?->note ?? '';
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

        if (BlindCountSession::isBlockedFor(auth()->id(), $vendor->id, $this->frequency, $this->customDays)) {
            Notification::make()->title('You have already completed a count within this period.')->warning()->send();
            return;
        }

        $productIds = $this->buildProductOrder($vendor->id);

        if (empty($productIds)) {
            Notification::make()->title('No published products found to count.')->warning()->send();
            return;
        }

        $session = BlindCountSession::create([
            'vendor_id'        => $vendor->id,
            'storekeeper_a_id' => auth()->id(),
            'status'           => 'a_counting',
            'frequency'        => $this->frequency,
            'custom_days'      => $this->frequency === 'custom' ? $this->customDays : null,
            'by_category'      => $this->byCategory,
            'product_order'    => $productIds,
        ]);

        $this->sessionId       = $session->id;
        $this->currentPosition = 1;
        $this->count           = 0;
        $this->note            = '';
        $this->canUndo         = false;
    }

    private function buildProductOrder(int $vendorId): array
    {
        $products = Product::published()
            ->where('vendor_id', $vendorId)
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

        if (BlindCountSession::isBlockedFor(auth()->id(), $vendor->id, $session->frequency, $session->custom_days)) {
            Notification::make()->title('You have already completed a count within this period.')->warning()->send();
            return;
        }

        $session->update(['storekeeper_b_id' => auth()->id()]);
        $this->restorePosition($session->fresh());
    }

    public function increment(): void
    {
        if (! $this->isParticipant()) return;
        $this->count++;
    }

    public function decrement(): void
    {
        if (! $this->isParticipant()) return;
        $this->count = max(0, $this->count - 1);
    }

    public function next(): void
    {
        if (! $this->isParticipant()) return;

        $this->saveCurrentEntry();

        $total = $this->getTotalProducts();
        if ($this->currentPosition < $total) {
            $this->currentPosition++;
            $this->loadCountForPosition($this->currentPosition);
        }
    }

    public function previous(): void
    {
        if (! $this->isParticipant()) return;

        $this->saveCurrentEntry();

        if ($this->currentPosition > 1) {
            $this->currentPosition--;
            $this->loadCountForPosition($this->currentPosition);
        }
    }

    // Quick "item isn't on the shelf" action — counts as 0 and advances like next().
    public function markNotFound(): void
    {
        if (! $this->isParticipant()) return;

        $this->count = 0;
        $this->note  = 'Not found';
        $this->next();
    }

    // Reverts whichever entry saveCurrentEntry() last overwrote back to its
    // previous value. One-shot — not a multi-step undo stack.
    public function undoLast(): void
    {
        if (! $this->isParticipant() || ! $this->canUndo || $this->undoPosition === null) return;

        $session   = $this->getSession();
        if (! $session) return;

        $productId = $session->product_order[$this->undoPosition - 1] ?? null;
        if (! $productId) return;

        BlindCountEntry::updateOrCreate(
            [
                'blind_count_session_id' => $session->id,
                'user_id'                => auth()->id(),
                'product_id'             => $productId,
            ],
            [
                'position'   => $this->undoPosition,
                'count'      => $this->undoPreviousCount,
                'note'       => $this->undoPreviousNote,
                'counted_at' => $this->undoPreviousCount !== null ? now() : null,
            ]
        );

        $this->currentPosition = $this->undoPosition;
        $this->loadCountForPosition($this->undoPosition);

        $this->canUndo           = false;
        $this->undoPosition      = null;
        $this->undoPreviousCount = null;
        $this->undoPreviousNote  = null;

        Notification::make()->title('Reverted to previous value.')->success()->send();
    }

    // Resolves a scanned/typed barcode to a product in this session and jumps to it.
    public function jumpToBarcode(string $barcode): void
    {
        if (! $this->isParticipant()) return;

        $session = $this->getSession();
        if (! $session) return;

        $barcode = trim($barcode);
        if ($barcode === '') return;

        $product = Product::where('vendor_id', $session->vendor_id)
            ->where(fn ($q) => $q->where('barcode', $barcode)->orWhere('sku', $barcode))
            ->first();

        if (! $product) {
            Notification::make()->title("No product found for \"{$barcode}\".")->warning()->send();
            return;
        }

        $position = array_search($product->id, $session->product_order, true);

        if ($position === false) {
            Notification::make()->title("{$product->name} isn't part of this count session.")->warning()->send();
            return;
        }

        $this->goToPosition($position + 1);
    }

    public function goToPosition(int $position): void
    {
        if (! $this->isParticipant()) return;

        $this->saveCurrentEntry();
        $this->currentPosition = $position;
        $this->loadCountForPosition($position);
        $this->showSearch  = false;
        $this->searchQuery = '';
    }

    public function submitAll(): void
    {
        if (! $this->isParticipant()) return;

        $this->saveCurrentEntry();

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

        $this->currentPosition = 1;
        $this->count           = 0;
        $this->note            = '';
        $this->canUndo         = false;

        Notification::make()->title('Session reset. Storekeeper A can start their count over.')->success()->send();
    }

    private function saveCurrentEntry(): void
    {
        if (! $this->isParticipant()) return;

        $session   = $this->getSession();
        if (! $session) return;

        $productId = $session->product_order[$this->currentPosition - 1] ?? null;
        if (! $productId) return;

        $note     = $this->note !== '' ? $this->note : null;
        $existing = BlindCountEntry::where('blind_count_session_id', $session->id)
            ->where('user_id', auth()->id())
            ->where('product_id', $productId)
            ->first();

        // Nothing changed since it was last saved — skip the snapshot/toast noise
        if ($existing && $existing->count === $this->count && $existing->note === $note) {
            return;
        }

        $this->undoPosition      = $this->currentPosition;
        $this->undoPreviousCount = $existing?->count;
        $this->undoPreviousNote  = $existing?->note;
        $this->canUndo           = true;

        BlindCountEntry::updateOrCreate(
            [
                'blind_count_session_id' => $session->id,
                'user_id'                => auth()->id(),
                'product_id'             => $productId,
            ],
            [
                'position'   => $this->currentPosition,
                'count'      => $this->count,
                'note'       => $note,
                'counted_at' => now(),
            ]
        );

        $product = Product::find($productId);
        $this->dispatch('entry-saved', productName: $product?->name ?? 'Item', count: $this->count);
    }

    private function loadCountForPosition(int $position): void
    {
        $session   = $this->getSession();
        if (! $session) return;

        $productId = $session->product_order[$position - 1] ?? null;
        if (! $productId) return;

        $entry       = BlindCountEntry::where('blind_count_session_id', $session->id)
            ->where('user_id', auth()->id())
            ->where('product_id', $productId)
            ->first();
        $this->count = $entry?->count ?? 0;
        $this->note  = $entry?->note ?? '';
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
                $difference = $count - (int) $product->stock_quantity;
                $matched    = $difference === 0;

                AuditSession::create([
                    'vendor_id'        => $session->vendor_id,
                    'product_id'       => $productId,
                    'storekeeper_a_id' => $session->storekeeper_a_id,
                    'storekeeper_b_id' => null,
                    'count_a'          => $count,
                    'count_b'          => null,
                    'status'           => $matched ? 'verified' : 'discrepancy',
                ]);

                if (! $matched) {
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

                AuditSession::create([
                    'vendor_id'        => $session->vendor_id,
                    'product_id'       => $productId,
                    'storekeeper_a_id' => $session->storekeeper_a_id,
                    'storekeeper_b_id' => $session->storekeeper_b_id,
                    'count_a'          => $countA,
                    'count_b'          => $countB,
                    'status'           => $matched ? 'verified' : 'discrepancy',
                ]);

                if ($matched) {
                    $product    = Product::find($productId);
                    $difference = $countB - (int) $product->stock_quantity;
                    if ($difference !== 0) {
                        $adjustStock->execute(
                            productId:       $productId,
                            quantityChanged: $difference,
                            transactionType: 'audit_correction',
                            userId:          $session->storekeeper_b_id,
                            reference:       "Inventory Count #{$session->id}",
                            description:     "Verified count. System had {$product->stock_quantity}, found {$countB}."
                        );
                    }
                } else {
                    $discrepancies++;
                }
            }
        });

        // Notify managers — runs outside the transaction so a notification failure
        // never rolls back the audit records that were just created
        $this->notifyManagersOfDiscrepancies($session->vendor_id, $discrepancies);

        return $discrepancies;
    }
}
