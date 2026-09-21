<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\AuditSession;
use App\Models\InventoryLedger;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Undoes the fallout of the solo-audit store-scoping bug (fixed alongside
 * this command): a solo inventory count verified through the Audit Sessions
 * page named no branch of its own, so ProcessAuditCountAction measured the
 * correction against the vendor-wide stock mirror and wrote it to the
 * vendor's default store — never the branch that was actually counted. The
 * counted branch's own figure was left exactly as wrong as it was before the
 * count, while a store nobody counted got an adjustment that means nothing.
 *
 * This only ever touches audit_correction entries whose reference starts
 * "Audit #" — the solo-verify path. "Audit Override #" (manager override)
 * and "Inventory Count #" (synchronous blind-count match) already resolved
 * their store correctly before this bug was ever found, so they are left
 * alone; correcting them again would be the second wrong write.
 */
class FixMisdirectedAuditCorrectionsCommand extends Command
{
    protected $signature = 'inventory:fix-misdirected-audit
                            {vendor : Vendor id, or a substring of its name}
                            {branch : Store id, or a substring of its name — the branch that was actually counted}
                            {--since= : Only audits verified on or after this date/time, e.g. "2026-09-19"}
                            {--force : Actually write the reversal and the correction. Without this, only reports the plan.}';

    protected $description = 'Reverse a solo audit correction that landed on the default store instead of the branch actually counted, and apply it there instead';

    public function handle(): int
    {
        $vendor = $this->resolveVendor($this->argument('vendor'));

        if (! $vendor) {
            $this->error('No vendor matches that id or name.');

            return self::FAILURE;
        }

        $branch = $this->resolveStore($vendor, $this->argument('branch'));

        if (! $branch) {
            $this->error('No store matches that id or name for this vendor.');

            return self::FAILURE;
        }

        $this->info("Vendor: {$vendor->name} (#{$vendor->id})");
        $this->info("Branch being corrected: {$branch->name} (#{$branch->id})");

        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;

        // The buggy path always fell back to the vendor's default store,
        // unconditionally — it never passed a store at all. So any
        // "Audit #" correction that landed anywhere other than this branch
        // is a candidate, not only ones that landed on today's default.
        $entries = InventoryLedger::where('vendor_id', $vendor->id)
            ->where('transaction_type', 'audit_correction')
            ->where('reference', 'like', 'Audit #%')
            ->where('store_id', '!=', $branch->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderBy('created_at')
            ->get();

        if ($entries->isEmpty()) {
            $this->info('No misdirected "Audit #" corrections found for this vendor in that window.');

            return self::SUCCESS;
        }

        $plan = [];

        foreach ($entries as $entry) {
            $auditId = (int) str_replace('Audit #', '', $entry->reference);
            $audit   = AuditSession::find($auditId);

            if (! $audit || ! in_array($audit->status, ['verified'], true)) {
                continue;
            }

            // Only the audits this branch's own storekeeper actually verified
            // stay in the plan — a correction genuinely meant for some other
            // branch is not this command's business, and guessing wrong here
            // would just create a second misdirected write.
            if ((int) $audit->product?->store_id !== $branch->id) {
                continue;
            }

            $landedStore = Store::find($entry->store_id);

            $currentAtBranch = (int) (ProductStoreStock::where('product_id', $audit->product_id)
                ->where('store_id', $branch->id)
                ->value('quantity') ?? 0);

            $correctDelta = $audit->countedQuantity() - $currentAtBranch;

            $plan[] = [
                'entry'         => $entry,
                'audit'         => $audit,
                'landed_store'  => $landedStore,
                'reverse_delta' => -$entry->quantity_change,
                'correct_delta' => $correctDelta,
            ];
        }

        if ($plan === []) {
            $this->info('Found misdirected entries, but none trace back to a verified audit whose product is homed at this branch.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(count($plan).' correction(s) to move:');

        $this->table(
            ['Audit #', 'Product', 'Counted', 'Landed on', 'Reverse there', "New delta at {$branch->name}"],
            collect($plan)->map(fn (array $p) => [
                $p['audit']->id,
                $p['audit']->product?->name ?? "Product #{$p['audit']->product_id}",
                $p['audit']->countedQuantity(),
                $p['landed_store']?->name ?? "Store #{$p['entry']->store_id}",
                $p['reverse_delta'],
                $p['correct_delta'],
            ])->all(),
        );

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run — nothing written. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        $fixed  = 0;
        $failed = [];

        foreach ($plan as $p) {
            /** @var AuditSession $audit */
            $audit = $p['audit'];

            try {
                DB::transaction(function () use ($p, $audit, $branch) {
                    if ($p['reverse_delta'] !== 0) {
                        app(AdjustStockAction::class)->execute(
                            productId: $audit->product_id,
                            quantityChanged: $p['reverse_delta'],
                            transactionType: 'audit_correction',
                            reference: "Reversal — Audit #{$audit->id}",
                            description: 'Undoing a correction that landed on the wrong store — see inventory:fix-misdirected-audit.',
                            auditSessionId: $audit->id,
                            store: $p['entry']->store_id,
                        );
                    }

                    if ($p['correct_delta'] !== 0) {
                        app(AdjustStockAction::class)->execute(
                            productId: $audit->product_id,
                            quantityChanged: $p['correct_delta'],
                            transactionType: 'audit_correction',
                            reference: "Corrected — Audit #{$audit->id}",
                            description: "Applied to the branch actually counted. System expected {$audit->system_quantity}, count found {$audit->countedQuantity()}.",
                            auditSessionId: $audit->id,
                            store: $branch->id,
                        );
                    }
                });

                $fixed++;
            } catch (Throwable $e) {
                $failed[] = ['audit' => $audit->id, 'product' => $audit->product?->name, 'reason' => $e->getMessage()];
            }
        }

        $this->info("{$fixed} correction(s) moved to {$branch->name}.");

        if ($failed !== []) {
            $this->newLine();
            $this->warn(count($failed).' could not be fixed:');

            foreach ($failed as $f) {
                $this->line("  Audit #{$f['audit']} {$f['product']}: {$f['reason']}");
            }
        }

        return self::SUCCESS;
    }

    private function resolveVendor(string|int $term): ?Vendor
    {
        $term = (string) $term;

        if (ctype_digit($term)) {
            return Vendor::find((int) $term);
        }

        return Vendor::where('name', 'like', "%{$term}%")->first();
    }

    private function resolveStore(Vendor $vendor, string|int $term): ?Store
    {
        $term  = (string) $term;
        $query = Store::where('vendor_id', $vendor->id);

        if (ctype_digit($term)) {
            return $query->find((int) $term);
        }

        return $query->where('name', 'like', "%{$term}%")->first();
    }
}
