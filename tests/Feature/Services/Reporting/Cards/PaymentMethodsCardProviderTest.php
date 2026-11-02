<?php

use App\Models\PosSale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\Cards\CardSummary;
use App\Services\Reporting\Cards\PaymentMethodsCardProvider;
use Carbon\CarbonImmutable;

function paymentCardVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Payment Card Store '.uniqid()]);

    return compact('owner', 'vendor');
}

function paymentCardSale(array $data, float $total, string $method, ?CarbonImmutable $at = null): PosSale
{
    return PosSale::create([
        'reference' => 'POS-PM-'.uniqid(),
        'vendor_id' => $data['vendor']->id,
        'cashier_id' => $data['owner']->id,
        'subtotal' => $total,
        'vat_amount' => 0,
        'total' => $total,
        'payment_method' => $method,
        'status' => 'completed',
        'completed_at' => $at ?? CarbonImmutable::now(),
    ]);
}

test('headline lists today\'s tenders, largest first, and compares with yesterday', function () {
    $data = paymentCardVendor();
    paymentCardSale($data, 5000, 'cash');
    paymentCardSale($data, 8000, 'bank_transfer');
    paymentCardSale($data, 1000, 'cash', CarbonImmutable::now()->subDay());

    $summary = app(PaymentMethodsCardProvider::class)->summarize($data['vendor']->id);

    expect($summary->headline)->toBe('₦13,000.00 received today — Transfer ₦8,000.00 · Cash ₦5,000.00')
        ->and($summary->comparison)->toBe('vs ₦1,000.00 yesterday')
        ->and($summary->comparisonDirection)->toBe('up')
        ->and($summary->urgency)->toBe(CardSummary::URGENCY_CALM)
        ->and($summary->hasLink())->toBeTrue();
});

test('a day mostly sold on credit is flagged', function () {
    $data = paymentCardVendor();
    paymentCardSale($data, 1000, 'cash');
    paymentCardSale($data, 3000, 'debt');

    $summary = app(PaymentMethodsCardProvider::class)->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_URGENT)
        ->and($summary->note)->toContain('₦3,000.00 sold on credit (75% of sales)');
});

test('a day with no shop sales says so plainly', function () {
    $data = paymentCardVendor();

    $summary = app(PaymentMethodsCardProvider::class)->summarize($data['vendor']->id);

    expect($summary->headline)->toBe('No shop sales yet today')
        ->and($summary->urgency)->toBe(CardSummary::URGENCY_CALM);
});
