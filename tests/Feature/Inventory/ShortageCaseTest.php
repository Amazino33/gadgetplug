<?php

use App\Models\AccountabilityLedgerEntry;
use App\Models\AuditSession;
use App\Models\Category;
use App\Models\InventoryShortageCase;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ShortageCaseService;

// A case records who is answerable and what the owner decided. Stock was
// already corrected at commit; nothing here touches it.

function caseContext(array $productAttributes = []): array
{
    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Leisure Hub']);
    $vendor->users()->attach($staff->id);

    $category = Category::firstOrCreate(['name' => 'Chargers']);

    $product = Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $productAttributes));

    return compact('owner', 'staff', 'vendor', 'product');
}

function countLine(array $c, int $counted, int $systemQty = 10, string $status = 'verified'): AuditSession
{
    return AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $c['product']->id,
        'system_quantity'  => $systemQty,
        'storekeeper_a_id' => $c['staff']->id,
        'count_a'          => $counted,
        'storekeeper_b_id' => $c['owner']->id,
        'count_b'          => $counted,
        'status'           => $status,
    ]);
}

// ── Opening ──────────────────────────────────────────────────────────────────

it('opens exactly one case for a non-zero variance', function () {
    $c = caseContext();
    $line = countLine($c, counted: 7);

    $case = app(ShortageCaseService::class)->openForCountLine($line);

    expect($case)->not->toBeNull()
        ->and($case->status)->toBe('pending_disposition')
        ->and($case->shortage_qty)->toBe(3)
        ->and((float) $case->charge_amount)->toBe(15900.0)
        ->and((float) $case->cost_component)->toBe(7710.0)
        ->and((float) $case->margin_component)->toBe(8190.0)
        // No assigned-storekeeper concept exists, so nobody is presumed liable.
        ->and($case->charged_storekeeper_id)->toBeNull()
        ->and(InventoryShortageCase::count())->toBe(1);
});

it('opens no case for a balanced line', function () {
    $c = caseContext();
    $line = countLine($c, counted: 10);

    expect(app(ShortageCaseService::class)->openForCountLine($line))->toBeNull()
        ->and(InventoryShortageCase::count())->toBe(0);
});

it('does not open a second case when a count is re-committed', function () {
    $c = caseContext();
    $line = countLine($c, counted: 7);
    $service = app(ShortageCaseService::class);

    $first  = $service->openForCountLine($line);
    $second = $service->openForCountLine($line);

    expect($second->id)->toBe($first->id)
        ->and(InventoryShortageCase::count())->toBe(1);
});

it('opens no case when the line has no recorded baseline', function () {
    $c = caseContext();
    $line = countLine($c, counted: 7);
    $line->update(['system_quantity' => null]);

    expect(app(ShortageCaseService::class)->openForCountLine($line->fresh()))->toBeNull();
});

it('opens a case for an overage too', function () {
    $c = caseContext();
    $line = countLine($c, counted: 13);

    $case = app(ShortageCaseService::class)->openForCountLine($line);

    expect($case->shortage_qty)->toBe(3);
});

// ── Dispositions ─────────────────────────────────────────────────────────────

it('charge writes exactly one ledger entry from the frozen split', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->reassign($case, $c['staff']->id);

    $service->charge($case, $c['owner']->id, 'Confirmed missing at handover.');

    $entries = AccountabilityLedgerEntry::where('case_id', $case->id)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->entry_type)->toBe('charge')
        ->and((float) $entries->first()->amount)->toBe(15900.0)
        ->and((float) $entries->first()->cost_component)->toBe(7710.0)
        ->and($case->fresh()->status)->toBe('charged')
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))
            ->toBe(15900.0);
});

it('charging twice posts only one entry', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->reassign($case, $c['staff']->id);
    $service->charge($case, $c['owner']->id, 'First.');

    // The case is settled, so a second attempt is refused outright — and even
    // if it were not, the ledger key would collapse it to one entry.
    expect(fn () => $service->charge($case->fresh(), $c['owner']->id, 'Second.'))
        ->toThrow(RuntimeException::class, 'already settled');

    expect(AccountabilityLedgerEntry::where('case_id', $case->id)->count())->toBe(1);
});

it('refuses to charge a case with nobody assigned', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));

    expect(fn () => $service->charge($case, $c['owner']->id, 'Reason.'))
        ->toThrow(RuntimeException::class, 'Name who is accountable');

    expect(AccountabilityLedgerEntry::count())->toBe(0);
});

it('write-off leaves the storekeeper owing nothing', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->reassign($case, $c['staff']->id);

    $service->writeOff($case, $c['owner']->id, 'Damaged in transit, not their fault.');

    expect($case->fresh()->status)->toBe('written_off')
        ->and(AccountabilityLedgerEntry::count())->toBe(0)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))
            ->toBe(0.0);
});

it('requires a reason to charge or write off', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->reassign($case, $c['staff']->id);

    expect(fn () => $service->writeOff($case, $c['owner']->id, '  '))
        ->toThrow(InvalidArgumentException::class, 'reason is required');
});

it('keeps the snapshot frozen across investigate then charge', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->reassign($case, $c['staff']->id);
    $service->investigate($case, $c['owner']->id, 'Asking the night shift.');

    expect($case->fresh()->status)->toBe('investigating');

    // Repriced while the case sat open. The charge must still use the figures
    // frozen when the loss was established.
    $c['product']->update(['price' => 9000, 'cost_price' => 4000]);

    $service->charge($case->fresh(), $c['owner']->id, 'Nobody could account for it.');

    $entry = AccountabilityLedgerEntry::where('case_id', $case->id)->first();

    expect((float) $entry->amount)->toBe(15900.0)
        ->and((float) $entry->unit_price_snapshot)->toBe(5300.0)
        ->and((float) $case->fresh()->charge_amount)->toBe(15900.0);
});

it('will not reassign or dispose a settled case', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->writeOff($case, $c['owner']->id, 'Absorbed.');

    expect(fn () => $service->reassign($case->fresh(), $c['staff']->id))
        ->toThrow(RuntimeException::class, 'already settled');
});

it('refuses to assign someone who is not a member of the store', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));

    expect(fn () => $service->reassign($case, User::factory()->create()->id))
        ->toThrow(RuntimeException::class, 'not a member of this store');
});

// ── Authorization ────────────────────────────────────────────────────────────

it('denies disposition to a storekeeper at the policy layer', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));

    // Not merely a hidden button — the gate itself refuses.
    expect($c['staff']->can('dispose', $case))->toBeFalse()
        ->and($c['staff']->can('reassign', $case))->toBeFalse()
        ->and($c['owner']->can('dispose', $case))->toBeTrue();
});

it('blocks anyone from disposing a shortage charged to themselves', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));

    // The owner is the charged party here. Owning the store does not license
    // signing off your own shortage.
    $service->reassign($case, $c['owner']->id);

    expect($c['owner']->can('dispose', $case->fresh()))->toBeFalse()
        // Nor may they reassign it away and then dispose it.
        ->and($c['owner']->can('reassign', $case->fresh()))->toBeFalse();
});

it('lets the owner dispose a case charged to someone else', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->reassign($case, $c['staff']->id);

    expect($c['owner']->can('dispose', $case->fresh()))->toBeTrue();
});

it('does not allow re-disposing a finished case even for the owner', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->writeOff($case, $c['owner']->id, 'Absorbed.');

    expect($c['owner']->can('dispose', $case->fresh()))->toBeFalse();
});

it('still allows disposing a case that is being investigated', function () {
    $c = caseContext();
    $service = app(ShortageCaseService::class);

    $case = $service->openForCountLine(countLine($c, counted: 7));
    $service->investigate($case, $c['owner']->id, 'Checking.');

    expect($c['owner']->can('dispose', $case->fresh()))->toBeTrue();
});

// ── Missing price ────────────────────────────────────────────────────────────

it('opens a cost-only case when the product has no retail price', function () {
    $c = caseContext(['price' => 0]);

    $case = app(ShortageCaseService::class)->openForCountLine(countLine($c, counted: 7));

    expect($case->price_fallback)->toBeTrue()
        ->and((float) $case->charge_amount)->toBe(7710.0)
        ->and((float) $case->margin_component)->toBe(0.0);
});
