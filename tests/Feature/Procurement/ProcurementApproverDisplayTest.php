<?php

use App\Filament\Vendor\Resources\Procurements\Pages\ListProcurements;
use App\Filament\Vendor\Resources\Procurements\Pages\ViewProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ReviewHelpers.php';

uses(RefreshDatabase::class);

/**
 * Who actually received a delivery, not just that somebody did.
 *
 * The status badge already says "Approved" — this is the name a storekeeper
 * would otherwise have to go and ask around for.
 */
test('the record screen shows who approved it once agreed', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->approve($delivery, $ctx['checker'], $ctx['store']->id);

    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);

    $html = Livewire::test(ViewProcurement::class, ['record' => $delivery->getRouteKey()])->html();

    expect($html)->toContain('Approved By')
        ->and($html)->toContain($ctx['checker']->name);
});

test('a pending delivery shows no approver, because there is none yet', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);

    $html = Livewire::test(ViewProcurement::class, ['record' => $delivery->getRouteKey()])->html();

    expect($html)->not->toContain('Approved By');
});

test('the approver name also shows on the list, next to the items', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->approve($delivery, $ctx['checker'], $ctx['store']->id);

    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);

    $html = Livewire::test(ListProcurements::class)->html();

    expect($html)->toContain('Approved by')
        ->and($html)->toContain($ctx['checker']->name);
});
