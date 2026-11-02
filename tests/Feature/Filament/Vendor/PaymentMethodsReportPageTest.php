<?php

use App\Filament\Vendor\Pages\PaymentMethodsReport;
use App\Filament\Vendor\Pages\SalesReport;
use App\Models\PosSale;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpPaymentMethodsReport(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Payment Methods Page Store']);

    foreach (['cash' => 2000, 'card' => 1500, 'debt' => 700] as $method => $total) {
        PosSale::create([
            'reference' => 'POS-PMPAGE-'.$method,
            'vendor_id' => $vendor->id,
            'cashier_id' => $owner->id,
            'subtotal' => $total,
            'vat_amount' => 0,
            'total' => $total,
            'payment_method' => $method,
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);

    return compact('owner', 'vendor');
}

test('the payment methods page shows the period totals and a row per day', function () {
    setUpPaymentMethodsReport();

    Livewire::test(PaymentMethodsReport::class)
        ->assertSee('Received from sales')
        ->assertSee('₦3,500.00')
        ->assertSee('Day by day')
        ->assertSee(CarbonImmutable::now(config('reporting.timezone'))->format('D j M Y'))
        ->assertSee('₦700.00');
});

test('the payment methods page downloads its day-by-day table as CSV', function () {
    setUpPaymentMethodsReport();

    Livewire::test(PaymentMethodsReport::class)
        ->callAction('downloadCsv')
        ->assertFileDownloaded();

    $page = new PaymentMethodsReport;
    $page->filters = ['preset' => 'today'];

    ob_start();
    $page->downloadCsv()->sendContent();
    $body = ob_get_clean();

    $today = CarbonImmutable::now(config('reporting.timezone'))->toDateString();

    expect($body)->toContain('Date,Cash,"Card (POS)","Bank transfer","On credit","Total sales","Debts repaid",Refunds')
        ->and($body)->toContain("{$today},2000,1500,0,700,4200,0,0")
        ->and($body)->toContain('Total,2000,1500,0,700,4200,0,0');
});

test('the sales report still shows its payment methods section', function () {
    setUpPaymentMethodsReport();

    Livewire::test(SalesReport::class)
        ->assertSee('Payment methods')
        ->assertSee('Card (POS)');
});
