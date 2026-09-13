<?php

use App\Models\User;
use App\Models\UserTourProgress;
use App\Models\Vendor;

function tourContext(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Tour Store']);

    $otherOwner = User::factory()->create();
    $otherVendor = Vendor::create(['user_id' => $otherOwner->id, 'name' => 'Other Store']);

    return compact('owner', 'vendor', 'otherOwner', 'otherVendor');
}

it('records that a tour was offered and stops offering it again', function () {
    $ctx = tourContext();

    expect(UserTourProgress::seenKeys($ctx['owner']->id, $ctx['vendor']->id))->toBe([]);

    $this->actingAs($ctx['owner'])
        ->postJson(route('tours.progress'), [
            'tour_key' => 'record-procurement',
            'vendor_id' => $ctx['vendor']->id,
            'status' => 'offered',
        ])
        ->assertOk();

    expect(UserTourProgress::seenKeys($ctx['owner']->id, $ctx['vendor']->id))
        ->toBe(['record-procurement']);
});

it('keeps one row per person per store per tour however often it is sent', function () {
    $ctx = tourContext();

    foreach (['offered', 'dismissed', 'offered'] as $status) {
        $this->actingAs($ctx['owner'])
            ->postJson(route('tours.progress'), [
                'tour_key' => 'record-procurement',
                'vendor_id' => $ctx['vendor']->id,
                'status' => $status,
            ])
            ->assertOk();
    }

    expect(UserTourProgress::count())->toBe(1);
});

it('never downgrades a completed tour back to dismissed', function () {
    $ctx = tourContext();

    UserTourProgress::record($ctx['owner']->id, $ctx['vendor']->id, 'add-product', 'completed');
    UserTourProgress::record($ctx['owner']->id, $ctx['vendor']->id, 'add-product', 'dismissed');

    expect(UserTourProgress::first()->status)->toBe('completed');
});

it('does not silence a tour for a colleague at the same store', function () {
    $ctx = tourContext();

    $storekeeper = User::factory()->create();
    $ctx['vendor']->users()->attach($storekeeper->id);

    UserTourProgress::record($ctx['owner']->id, $ctx['vendor']->id, 'record-procurement', 'dismissed');

    // The owner waving it away is not a decision on behalf of somebody hired
    // later. This is the reason the table is keyed per user, not per vendor.
    expect(UserTourProgress::seenKeys($storekeeper->id, $ctx['vendor']->id))->toBe([]);
});

it('does not carry a dismissal across to another store the same person runs', function () {
    $ctx = tourContext();

    $ctx['otherVendor']->users()->attach($ctx['owner']->id);

    UserTourProgress::record($ctx['owner']->id, $ctx['vendor']->id, 'daily-report', 'dismissed');

    expect(UserTourProgress::seenKeys($ctx['owner']->id, $ctx['otherVendor']->id))->toBe([]);
});

it('refuses a progress row written against a store you have nothing to do with', function () {
    $ctx = tourContext();

    $this->actingAs($ctx['owner'])
        ->postJson(route('tours.progress'), [
            'tour_key' => 'record-procurement',
            'vendor_id' => $ctx['otherVendor']->id,
            'status' => 'offered',
        ])
        ->assertForbidden();

    expect(UserTourProgress::count())->toBe(0);
});

it('rejects a tour key that is not in the registry', function () {
    $ctx = tourContext();

    $this->actingAs($ctx['owner'])
        ->postJson(route('tours.progress'), [
            'tour_key' => 'drop-tables',
            'vendor_id' => $ctx['vendor']->id,
            'status' => 'offered',
        ])
        ->assertUnprocessable();
});

it('rejects a status outside the three it knows', function () {
    $ctx = tourContext();

    $this->actingAs($ctx['owner'])
        ->postJson(route('tours.progress'), [
            'tour_key' => 'record-procurement',
            'vendor_id' => $ctx['vendor']->id,
            'status' => 'whatever',
        ])
        ->assertUnprocessable();
});

it('turns away an anonymous request', function () {
    $ctx = tourContext();

    $this->postJson(route('tours.progress'), [
        'tour_key' => 'record-procurement',
        'vendor_id' => $ctx['vendor']->id,
        'status' => 'offered',
    ])->assertUnauthorized();
});
