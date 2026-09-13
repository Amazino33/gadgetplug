<?php

// Shared fixtures for the cash-up suites.
//
// In a helper file for the same reason tests/Feature/Cash/Helpers.php is: Pest
// loads every test file into one global function namespace, so a helper declared
// in a sibling test only exists when that sibling happens to load too, and
// running a file on its own then fails.

use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;

/** A vendor, its default branch, and a cashier assigned to that branch. */
function cashUpContext(): array
{
    $owner = User::factory()->create(['name' => 'Oga']);

    $vendor = Vendor::create([
        'user_id' => $owner->id,
        'name'    => 'Cash-Up Vendor '.uniqid(),
    ]);

    $store = Store::where('vendor_id', $vendor->id)->where('is_default', true)->firstOrFail();

    $cashier = User::factory()->create(['name' => 'Nkechi']);
    $cashier->stores()->syncWithoutDetaching([$store->id]);

    return compact('owner', 'vendor', 'store', 'cashier');
}

/** An open session, exactly as the open endpoint will write one. */
function openCashUp(array $ctx, array $over = []): PosSession
{
    return PosSession::create(array_merge([
        'vendor_id'     => $ctx['vendor']->id,
        'store_id'      => $ctx['store']->id,
        'cashier_id'    => $ctx['cashier']->id,
        'terminal_id'   => 'MPT-001',
        'business_date' => '2026-09-11',
        'opening_float' => 20000,
        'opened_at'     => now(),
        'status'        => PosSession::STATUS_OPEN,
    ], $over));
}

/**
 * Close a session the way Phase 3 will: counts and the figures they were
 * measured against land together, in the one update that leaves 'open'.
 */
function closeCashUp(PosSession $session, array $over = []): PosSession
{
    $session->update(array_merge([
        'counted_cash'      => 100000,
        'counted_terminal'  => 50000,
        'expected_cash'     => 100000,
        'expected_terminal' => 50000,
        'cash_variance'     => 0,
        'terminal_variance' => 0,
        'breakdown'         => ['cash_sales' => 80000],
        'status'            => PosSession::STATUS_PENDING_REVIEW,
        'closed_at'         => now(),
    ], $over));

    return $session->refresh();
}
