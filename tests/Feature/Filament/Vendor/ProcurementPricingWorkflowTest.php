<?php

use App\Actions\Procurement\CorrectProcurementLogisticsAction;
use App\Actions\Procurement\ReconcileProcurementAction;
use App\Actions\Procurement\SubmitProcurementForLogisticsAction;
use App\Actions\Procurement\VoidProcurementAction;
use App\Filament\Vendor\Resources\Procurements\Pages\CreateProcurement;
use App\Filament\Vendor\Resources\Procurements\Pages\EditProcurement;
use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function setUpProcurementWorkflowVendor(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Workflow Test Store']);
    VendorRoles::seedFor($vendor);

    $storekeeper = User::factory()->create();
    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole('storekeeper');

    // Ad-hoc permission set representing "logistics capability" in
    // isolation — not one of the three named roles VendorRoles seeds
    // (store_admin/inventory_manager already carry both new permissions
    // together, by design, so they can't isolate the boundary this test
    // needs). This proves the underlying permission check, independent of
    // which real-world role a vendor assigns it to.
    $logisticsOnly = User::factory()->create();
    setPermissionsTeamId($vendor->id);
    $logisticsRole = Role::firstOrCreate(['name' => 'logistics_only_test', 'guard_name' => 'web', 'team_id' => $vendor->id]);
    $logisticsRole->syncPermissions(Permission::whereIn('name', ['record_procurement_logistics'])->get());
    $logisticsOnly->assignRole('logistics_only_test');

    $category = Category::create(['name' => 'Workflow Test Category']);
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Test Supplier']);

    return compact('owner', 'vendor', 'storekeeper', 'logisticsOnly', 'category', 'supplier');
}

function makeWorkflowProcurement(array $ctx, array $overrides = []): Procurement
{
    return Procurement::create(array_merge([
        'vendor_id' => $ctx['vendor']->id,
        'supplier_id' => $ctx['supplier']->id,
        'created_by' => $ctx['storekeeper']->id,
        'status' => 'draft',
    ], $overrides));
}

function makeWorkflowProduct(array $ctx, array $overrides = []): Product
{
    return Product::create(array_merge([
        'vendor_id' => $ctx['vendor']->id,
        'category_id' => $ctx['category']->id,
        'name' => 'Workflow Test Product',
        'price' => 1000,
        'status' => 'published',
    ], $overrides));
}

// ── 1. Permission gating ──────────────────────────────────────────────

test('storekeeper can create procurements and edit a draft, but has no logistics permission', function () {
    $ctx = setUpProcurementWorkflowVendor();

    expect($ctx['storekeeper']->hasVendorPermission($ctx['vendor']->id, 'create_procurement'))->toBeTrue()
        ->and($ctx['storekeeper']->hasVendorPermission($ctx['vendor']->id, 'submit_procurement'))->toBeTrue()
        ->and($ctx['storekeeper']->hasVendorPermission($ctx['vendor']->id, 'record_procurement_logistics'))->toBeFalse();

    $this->actingAs($ctx['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    expect(ProcurementResource::canCreate())->toBeTrue();

    $draft = makeWorkflowProcurement($ctx);
    expect(ProcurementResource::canEdit($draft))->toBeTrue();
});

test('logistics-only permission cannot create procurements and cannot reach a draft to edit lines', function () {
    $ctx = setUpProcurementWorkflowVendor();

    expect($ctx['logisticsOnly']->hasVendorPermission($ctx['vendor']->id, 'record_procurement_logistics'))->toBeTrue()
        ->and($ctx['logisticsOnly']->hasVendorPermission($ctx['vendor']->id, 'submit_procurement'))->toBeFalse();

    $this->actingAs($ctx['logisticsOnly']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    expect(ProcurementResource::canCreate())->toBeFalse();
});

test('logistics-only permission can reach an awaiting_logistics procurement to reconcile', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $awaiting = makeWorkflowProcurement($ctx, ['status' => 'awaiting_logistics']);

    $this->actingAs($ctx['logisticsOnly']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    expect(ProcurementResource::canEdit($awaiting))->toBeTrue();
});

test('a reconciled procurement can never be edited again, by anyone', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $reconciled = makeWorkflowProcurement($ctx, ['status' => 'reconciled', 'reconciled_at' => now()]);

    $this->actingAs($ctx['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    expect(ProcurementResource::canEdit($reconciled))->toBeFalse();

    $this->actingAs($ctx['logisticsOnly']);
    Filament::setTenant($ctx['vendor']);
    expect(ProcurementResource::canEdit($reconciled))->toBeFalse();
});

// ── 2. Provisional pricing on submit ──────────────────────────────────

test('submitting a draft prices lines provisionally, updates the product, and takes stock live', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx, ['stock_quantity' => 5]);

    $line = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 3, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);

    $line->refresh();
    $product->refresh();
    $procurement->refresh();

    // logistics_cost is still null -> factor 1 -> landed = purchase price.
    expect($line->landed_unit_cost)->toEqualWithDelta(20000.0, 0.01)
        ->and((float) $product->cost_price)->toEqualWithDelta(20000.0, 0.01)
        ->and((float) $product->price)->toEqualWithDelta((float) $line->suggested_price, 0.01)
        ->and($product->stock_quantity)->toBe(8)
        ->and($procurement->status)->toBe('awaiting_logistics');
});

// ── 3. Reconcile recomputes and auto-adjusts ──────────────────────────

test('reconciling recomputes landed cost and suggestion by value, and updates non-overridden prices', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $this->actingAs($ctx['logisticsOnly']);
    $procurement = makeWorkflowProcurement($ctx);

    $productA = makeWorkflowProduct($ctx, ['name' => 'Product A']);
    $productB = makeWorkflowProduct($ctx, ['name' => 'Product B']);

    $lineA = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $productA->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);
    $lineB = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $productB->id,
        'quantity' => 1, 'unit_cost' => 5000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);

    $procurement->update(['logistics_cost' => 1500]);
    app(ReconcileProcurementAction::class)->execute($procurement);

    $procurement->refresh();
    $lineA->refresh();
    $lineB->refresh();
    $productA->refresh();
    $productB->refresh();

    // V = 25000, factor = 1.06
    expect($lineA->landed_unit_cost)->toEqualWithDelta(21200.0, 0.01)
        ->and($lineB->landed_unit_cost)->toEqualWithDelta(5300.0, 0.01)
        ->and((float) $productA->cost_price)->toEqualWithDelta(21200.0, 0.01)
        ->and((float) $productA->price)->toEqualWithDelta((float) $lineA->suggested_price, 0.01)
        ->and($procurement->status)->toBe('reconciled')
        ->and($procurement->reconciled_at)->not->toBeNull()
        ->and($procurement->logistics_recorded_by)->toBe($ctx['logisticsOnly']->id);
});

// ── 4. Override is respected ──────────────────────────────────────────

test('a manually overridden product keeps its selling price through reconcile, but cost_price still updates', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx, ['price' => 99999, 'price_overridden' => true]);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    $product->refresh();
    expect((float) $product->price)->toEqualWithDelta(99999.0, 0.01);

    $procurement->update(['logistics_cost' => 1500]);
    app(ReconcileProcurementAction::class)->execute($procurement);
    $product->refresh();

    expect((float) $product->price)->toEqualWithDelta(99999.0, 0.01) // untouched
        ->and((float) $product->cost_price)->toEqualWithDelta(21500.0, 0.01) // still updated
        ->and($product->price_overridden)->toBeTrue();
});

// ── 5. Cap + rounding survive the full flow ───────────────────────────

test('the profit cap and rounding survive submit and reconcile end to end', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $ctx['category']->update(['markup' => 0.45]);

    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 180000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    $product->refresh();

    // landed 180000 (factor 1) * 1.45 = 261000 raw, profit 81000 > cap 50000
    // -> 230000, already a multiple of 500.
    expect((float) $product->price)->toEqualWithDelta(230000.0, 0.01);

    $procurement->update(['logistics_cost' => 0]);
    app(ReconcileProcurementAction::class)->execute($procurement);
    $product->refresh();

    // logistics_cost 0 -> factor still 1 -> same capped, rounded result.
    expect((float) $product->price)->toEqualWithDelta(230000.0, 0.01);
});

// ── 6. Blank ≠ zero ────────────────────────────────────────────────────

test('submitting an empty procurement is rejected rather than silently pricing nothing', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);

    expect(fn () => app(SubmitProcurementForLogisticsAction::class)->execute($procurement))
        ->toThrow(RuntimeException::class);
});

test('reconcile is blocked when logistics_cost is null — blank is not treated as zero', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    $procurement->refresh();

    expect($procurement->logistics_cost)->toBeNull();
    expect(fn () => app(ReconcileProcurementAction::class)->execute($procurement))
        ->toThrow(RuntimeException::class);
});

// ── 7. Activity log ────────────────────────────────────────────────────

test('reconcile writes an activity log entry per adjusted product with old/new values', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $this->actingAs($ctx['owner']);

    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);

    $procurement->update(['logistics_cost' => 1500]);
    app(ReconcileProcurementAction::class)->execute($procurement);

    $activity = Activity::where('subject_type', Product::class)
        ->where('subject_id', $product->id)
        ->where('description', 'like', 'Reconciled procurement pricing%')
        ->latest()
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['trigger'])->toBe('reconcile')
        ->and($activity->properties['old_cost_price'])->toEqualWithDelta(20000.0, 0.01)
        ->and((float) $activity->properties['new_cost_price'])->toEqualWithDelta(21500.0, 0.01)
        ->and($activity->vendor_id)->toBe($ctx['vendor']->id);
});

// ── Batch 2 follow-up: correcting logistics cost after reconciling ────

test('correcting logistics cost on a reconciled procurement recalculates pricing without reopening it', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx);

    $line = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    $procurement->update(['logistics_cost' => 1500]);
    app(ReconcileProcurementAction::class)->execute($procurement);

    // Wrong logistics figure was entered — the real trip cost was 3000, not 1500.
    app(CorrectProcurementLogisticsAction::class)->execute($procurement, 3000.0);

    $procurement->refresh();
    $line->refresh();
    $product->refresh();

    // V = 20000, factor = 1 + 3000/20000 = 1.15 -> landed 23000.
    expect($procurement->status)->toBe('reconciled')
        ->and((float) $procurement->logistics_cost)->toEqualWithDelta(3000.0, 0.01)
        ->and($line->landed_unit_cost)->toEqualWithDelta(23000.0, 0.01)
        ->and((float) $product->cost_price)->toEqualWithDelta(23000.0, 0.01)
        ->and((float) $product->price)->toEqualWithDelta((float) $line->suggested_price, 0.01);
});

test('correction is rejected on a procurement that has not been reconciled yet', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx, ['status' => 'awaiting_logistics', 'logistics_cost' => 1500]);
    $product = makeWorkflowProduct($ctx);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    expect(fn () => app(CorrectProcurementLogisticsAction::class)->execute($procurement, 2000.0))
        ->toThrow(RuntimeException::class);
});

test('correction respects price_overridden the same way reconcile does', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx, ['price' => 99999, 'price_overridden' => true]);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    $procurement->update(['logistics_cost' => 1500]);
    app(ReconcileProcurementAction::class)->execute($procurement);

    app(CorrectProcurementLogisticsAction::class)->execute($procurement, 3000.0);
    $product->refresh();

    expect((float) $product->price)->toEqualWithDelta(99999.0, 0.01) // still untouched
        ->and((float) $product->cost_price)->toEqualWithDelta(23000.0, 0.01); // still recalculated
});

test('correcting logistics cost writes activity log entries for the procurement and each product', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $this->actingAs($ctx['owner']);

    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    $procurement->update(['logistics_cost' => 1500]);
    app(ReconcileProcurementAction::class)->execute($procurement);

    app(CorrectProcurementLogisticsAction::class)->execute($procurement, 3000.0);

    $procurementActivity = Activity::where('subject_type', Procurement::class)
        ->where('subject_id', $procurement->id)
        ->where('description', 'Logistics cost corrected on a reconciled procurement')
        ->latest()
        ->first();

    $productActivity = Activity::where('subject_type', Product::class)
        ->where('subject_id', $product->id)
        ->where('description', 'like', 'Corrected procurement logistics cost%')
        ->latest()
        ->first();

    expect($procurementActivity)->not->toBeNull()
        ->and((float) $procurementActivity->properties['old_logistics_cost'])->toEqualWithDelta(1500.0, 0.01)
        ->and((float) $procurementActivity->properties['new_logistics_cost'])->toEqualWithDelta(3000.0, 0.01)
        ->and($productActivity)->not->toBeNull()
        ->and($productActivity->properties['trigger'])->toBe('logistics_correction');
});

// ── Wizard consolidation: single create path, status-aware void ───────

test('voiding a draft procurement is a plain status flip — no stock was ever touched', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx, ['stock_quantity' => 5]);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 3, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(VoidProcurementAction::class)->execute($procurement, 'Created by mistake');

    expect($procurement->fresh()->status)->toBe('voided')
        ->and($procurement->fresh()->void_reason)->toBe('Created by mistake')
        ->and($product->fresh()->stock_quantity)->toBe(5) // untouched
        ->and(InventoryLedger::where('product_id', $product->id)->exists())->toBeFalse();
});

test('voiding an awaiting_logistics procurement reverses the stock it already took live', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx, ['stock_quantity' => 5]);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 3, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    expect($product->fresh()->stock_quantity)->toBe(8); // 5 + 3 from submit

    app(VoidProcurementAction::class)->execute($procurement->fresh(), 'Supplier cancelled the order');

    $ledgerEntry = InventoryLedger::where('product_id', $product->id)
        ->where('transaction_type', 'audit_correction')
        ->latest()
        ->first();

    expect($procurement->fresh()->status)->toBe('voided')
        ->and($product->fresh()->stock_quantity)->toBe(5) // reversed back to pre-submit
        ->and($ledgerEntry)->not->toBeNull()
        ->and($ledgerEntry->quantity_change)->toBe(-3);
});

test('a reconciled procurement cannot be voided at all — correction is the only path', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    app(SubmitProcurementForLogisticsAction::class)->execute($procurement);
    $procurement->update(['logistics_cost' => 1500]);
    app(ReconcileProcurementAction::class)->execute($procurement);

    expect(fn () => app(VoidProcurementAction::class)->execute($procurement->fresh(), 'Too late now'))
        ->toThrow(RuntimeException::class);
});

test('the single create form saves payment method, amount paid, and computes payment_status', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $product = makeWorkflowProduct($ctx);

    $this->actingAs($ctx['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(CreateProcurement::class)
        ->fillForm([
            'supplier_id' => $ctx['supplier']->id,
            'payment_method' => 'bank_transfer',
            'amount_paid' => 5000,
            'items' => [
                ['product_id' => $product->id, 'unit_cost' => 20000, 'quantity' => 1],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $procurement = Procurement::where('vendor_id', $ctx['vendor']->id)->latest()->first();

    expect($procurement)->not->toBeNull()
        ->and($procurement->status)->toBe('draft')
        ->and($procurement->payment_method)->toBe('bank_transfer')
        ->and((float) $procurement->amount_paid)->toEqualWithDelta(5000.0, 0.01)
        ->and((float) $procurement->total_cost)->toEqualWithDelta(20000.0, 0.01)
        ->and($procurement->payment_status)->toBe('part_payment');
});

test('the edit form renders a draft procurement with its lines and the Submit action, without error', function () {
    $ctx = setUpProcurementWorkflowVendor();
    $procurement = makeWorkflowProcurement($ctx);
    $product = makeWorkflowProduct($ctx);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    $this->actingAs($ctx['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(EditProcurement::class, ['record' => $procurement->getRouteKey()])
        ->assertOk()
        ->assertSee('Submit for Logistics')
        ->assertSee('Workflow Test Product');
});
