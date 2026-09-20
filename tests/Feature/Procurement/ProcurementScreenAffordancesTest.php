<?php

use App\Filament\Vendor\Resources\Procurements\Pages\ViewProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ReviewHelpers.php';

uses(RefreshDatabase::class);

/**
 * The things that only go wrong on a phone.
 *
 * Both of these were reported from the shop floor rather than found in a test,
 * and both are invisible at desktop width: a button that scrolls away on a long
 * delivery, and a form row you cannot tell apart from the ten identical rows
 * above it while the keyboard covers half the screen.
 *
 * Asserting on markup rather than behaviour, deliberately. Neither a sticky
 * offset nor an on-screen keyboard exists in a headless run, so what is worth
 * pinning down is that the mechanism is still wired in — nobody can quietly
 * delete it while refactoring the layout.
 */
test('approve stays on screen rather than at the end of a long delivery', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);

    $html = Livewire::test(ViewProcurement::class, ['record' => $delivery->getRouteKey()])->html();

    expect($html)->toContain('sticky bottom-0')
        ->and($html)->toContain('Approve &amp; Update Stock')
        // For phones with a home indicator, where a button flush to the bottom
        // sits under it and takes two taps.
        ->and($html)->toContain('safe-area-inset-bottom');
});

test('the item row being filled in is marked, numbered and kept clear of the keyboard', function () {
    $ctx = reviewContext();
    reviewProduct($ctx);

    $this->actingAs($ctx['keeper'])->withSession([
        'procurement.supplier_id' => $ctx['supplier']->id,
        'procurement.store_id'    => $ctx['store']->id,
    ]);

    $html = $this->get(route('procurement.items'))->assertOk()->getContent();

    // The row in hand is visually distinct from the identical cards above it.
    expect($html)->toContain('.item-row.is-active')
        ->and($html)->toContain('setActiveRow')
        // And carries a number, so a line can be talked about out loud.
        ->and($html)->toContain('row-badge')
        ->and($html)->toContain('renumberRows');

    // The keyboard covers the field rather than resizing the page, so the
    // covered height has to be measured and padded for.
    expect($html)->toContain('visualViewport')
        ->and($html)->toContain('--keyboard-inset');
});
