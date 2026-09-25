<?php

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The checkout no longer asks when the customer wants their order — the picker
// was a required step standing between a shopper and the Continue button, for a
// question the rider settles on WhatsApp anyway. Orders placed while it existed
// still carry the answer, and the vendor and admin tables still read it back,
// so the label stays covered even though nothing writes it any more.
test('the label reads back the recorded urgency, not just the date', function () {
    $make = fn (array $attributes = []) => Order::create(array_merge([
        'reference'        => 'GP-' . fake()->unique()->numerify('######'),
        'customer_name'    => 'Ada Customer',
        'customer_email'   => 'ada@example.com',
        'customer_phone'   => '08012345678',
        'shipping_address' => '12 Test Street',
        'total_amount'     => 50000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ], $attributes));

    $today = $make(['delivery_urgency' => 'today',     'preferred_delivery_date' => now()->toDateString()]);
    $sched = $make(['delivery_urgency' => 'scheduled', 'preferred_delivery_date' => now()->addDays(4)->toDateString()]);
    $none  = $make();

    expect($today->deliveryPreferenceLabel())->toBe('Today')
        ->and($sched->deliveryPreferenceLabel())->toBe(now()->addDays(4)->format('D, j M Y'))
        ->and($none->deliveryPreferenceLabel())->toBeNull();
});
