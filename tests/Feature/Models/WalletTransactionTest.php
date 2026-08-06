<?php

use App\Models\Affiliate;
use App\Models\User;
use App\Models\WalletTransaction;

test('a wallet transaction can never be updated once created', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    $transaction = WalletTransaction::create([
        'affiliate_id' => $affiliate->id,
        'type'         => 'credit',
        'amount'       => 100,
    ]);

    expect(fn () => $transaction->update(['amount' => 999]))
        ->toThrow(\LogicException::class, 'append-only');
});
