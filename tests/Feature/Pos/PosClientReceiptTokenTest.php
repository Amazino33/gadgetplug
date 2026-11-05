<?php

use App\Models\PosSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The till mints each sale's public token, so the QR on the paper can be
 * printed before the server has ever seen the sale — including offline.
 */
function tokenSyncBody(array $ctx, string $offlineId, array $extra = []): array
{
    return [
        'vendor_id' => $ctx['vendor']->id,
        'sales' => [array_merge(debtSalePayload($ctx, [
            'customer_id' => null,
            'payment_method' => 'cash',
            'amount_tendered' => 10000,
            'total' => 10000,
        ]), [
            'offline_id' => $offlineId,
            'completed_at' => now()->toIso8601String(),
        ], $extra)],
    ];
}

it('keeps the token the till minted when the sale syncs', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $response = $this->postJson('/api/pos/sync', tokenSyncBody($ctx, 'till-tok-1', ['public_token' => 'Ab3dEf6hIj9lMn2P']))
        ->assertOk();

    expect(PosSale::sole()->public_token)->toBe('Ab3dEf6hIj9lMn2P')
        ->and($response->json('results.0.status'))->toBe('synced');
});

it('returns the server id and token for each synced sale', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $response = $this->postJson('/api/pos/sync', tokenSyncBody($ctx, 'till-tok-2', ['public_token' => 'Zz9yXx8wVv7uTt6S']))
        ->assertOk();

    expect($response->json('results.0.id'))->toBe(PosSale::sole()->id)
        ->and($response->json('results.0.public_token'))->toBe('Zz9yXx8wVv7uTt6S');
});

it('makes a token itself for a sale from an older till that sent none', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $response = $this->postJson('/api/pos/sync', tokenSyncBody($ctx, 'till-tok-3'))->assertOk();

    expect(PosSale::sole()->public_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($response->json('results.0.public_token'))->toBe(PosSale::sole()->public_token);
});

it('refuses a malformed token for that sale without sinking the batch', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $body = tokenSyncBody($ctx, 'till-bad-token', ['public_token' => 'not a token!']);
    $body['sales'][] = tokenSyncBody($ctx, 'till-good-token', ['public_token' => 'Gg1hHh2iIi3jJj4K'])['sales'][0];

    $results = $this->postJson('/api/pos/sync', $body)->assertOk()->json('results');

    expect($results[0]['status'])->toBe('rejected')
        ->and($results[0]['message'])->toContain('malformed')
        ->and($results[1]['status'])->toBe('synced')
        ->and(PosSale::count())->toBe(1);
});

it('stays idempotent when the same sale is synced twice, and never rewrites the token', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $body = tokenSyncBody($ctx, 'till-twice', ['public_token' => 'Qq1wWw2eEe3rRr4T']);

    $first = $this->postJson('/api/pos/sync', $body)->assertOk()->json('results.0');
    $second = $this->postJson('/api/pos/sync', $body)->assertOk()->json('results.0');

    expect($second['status'])->toBe('duplicate')
        ->and($second['id'])->toBe($first['id'])
        ->and($second['public_token'])->toBe('Qq1wWw2eEe3rRr4T')
        ->and(PosSale::count())->toBe(1);

    // Even a replay that arrives carrying a different token cannot change it.
    $body['sales'][0]['public_token'] = 'Aa1sSs2dDd3fFf4G';
    $third = $this->postJson('/api/pos/sync', $body)->assertOk()->json('results.0');

    expect($third['public_token'])->toBe('Qq1wWw2eEe3rRr4T')
        ->and(PosSale::sole()->public_token)->toBe('Qq1wWw2eEe3rRr4T');
});

it('rejects a token that already opens a different sale, rather than overwriting or sharing it', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sync', tokenSyncBody($ctx, 'till-first', ['public_token' => 'Mm1nNn2bBb3vVv4C']))->assertOk();

    $clash = $this->postJson('/api/pos/sync', tokenSyncBody($ctx, 'till-second', ['public_token' => 'Mm1nNn2bBb3vVv4C']))
        ->assertOk()->json('results.0');

    expect($clash['status'])->toBe('rejected')
        ->and($clash['message'])->toContain('different sale')
        ->and(PosSale::count())->toBe(1)
        ->and(PosSale::sole()->reference)->toBe('POS-'.strtoupper(substr(md5('till-first'), 0, 8)));
});

it('also accepts the token on the direct online path', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', array_merge(debtSalePayload($ctx, [
        'customer_id' => null, 'payment_method' => 'cash', 'amount_tendered' => 10000,
    ]), ['offline_id' => 'till-direct', 'public_token' => 'Pp1oOo2iIi3uUu4Y']))->assertCreated();

    expect(PosSale::sole()->public_token)->toBe('Pp1oOo2iIi3uUu4Y');
});

it('rejects a malformed token on the direct online path', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', array_merge(debtSalePayload($ctx, [
        'customer_id' => null, 'payment_method' => 'cash', 'amount_tendered' => 10000,
    ]), ['public_token' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('public_token');
});

it('shows a friendly page, not a bare 404, for a receipt that has not synced yet', function () {
    $this->get('/receipt/Nn1mMm2lLl3kKk4J')
        ->assertOk()
        ->assertSee('available shortly')
        ->assertSee('check back in a few minutes');
});

it('still 404s a token that could never have been ours', function () {
    $this->get('/receipt/nope')->assertNotFound();
});

it('opens the real receipt once the sale has synced', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sync', tokenSyncBody($ctx, 'till-then-scan', ['public_token' => 'Ll1kKk2jJj3hHh4G']))->assertOk();

    $this->get('/receipt/Ll1kKk2jJj3hHh4G')->assertOk()->assertDontSee('available shortly');
});

it('gives the till the address to build its QR from, derived from the real route', function () {
    $ctx = debtTenderContext();
    $ctx['owner']->forceFill(['pos_pin' => Hash::make('1234')])->save();

    $vendor = $this->postJson('/api/pos/auth/login', ['vendor_id' => $ctx['vendor']->id, 'pin' => '1234'])
        ->assertOk()->json('vendor');

    expect($vendor['receipt_url_template'])
        ->toBe(str_replace('__TOKEN__', '{token}', route('receipt.public', ['token' => '__TOKEN__'])))
        ->and($vendor['receipt']['show_qr'])->toBeTrue();
});

it('hands the till the cashier and customer name in its history, for a matching reprint', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', array_merge(debtSalePayload($ctx, [
        'payment_method' => 'cash', 'amount_tendered' => 10000,
    ]), ['offline_id' => 'till-history', 'public_token' => 'Xx1cCc2vVv3bBb4N']))->assertCreated();

    $row = $this->getJson('/api/pos/sales/my-history?vendor_id='.$ctx['vendor']->id)->assertOk()->json('data.0');

    expect($row['public_token'])->toBe('Xx1cCc2vVv3bBb4N')
        ->and($row['cashier_name'])->toBe($ctx['owner']->name)
        ->and($row['customer']['name'])->toBe('Ada Obi');
});
