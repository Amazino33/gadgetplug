<?php

use App\Models\Affiliate;
use App\Models\AffiliateApplication;
use App\Models\User;
use Livewire\Volt\Volt;

test('a user with no application sees the form and can submit one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('pages.account.affiliate-apply')
        ->set('whatsapp', '+2348000000000')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->call('submit')
        ->assertSet('submitted', true);

    $application = AffiliateApplication::where('user_id', $user->id)->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('pending')
        ->and($application->whatsapp)->toBe('+2348000000000');
});

test('a user with a pending application sees the status banner, not the form', function () {
    $user = User::factory()->create();
    AffiliateApplication::create([
        'user_id'  => $user->id,
        'whatsapp' => '+2348000000000',
        'reason'   => 'Already applied, waiting on review, this text is long enough.',
        'status'   => 'pending',
    ]);
    $this->actingAs($user);

    Volt::test('pages.account.affiliate-apply')
        ->assertSee('Application Under Review')
        ->assertDontSee('Submit Application');
});

test('a rejected application shows the reason and allows reapplying', function () {
    $user = User::factory()->create();
    AffiliateApplication::create([
        'user_id'      => $user->id,
        'whatsapp'     => '+2348000000000',
        'reason'       => 'First attempt, long enough to pass the minimum length validation.',
        'status'       => 'rejected',
        'admin_notes'  => 'Not enough audience reach yet.',
    ]);
    $this->actingAs($user);

    Volt::test('pages.account.affiliate-apply')
        ->assertSee('Not enough audience reach yet.')
        ->assertSee('Submit Application');
});

test('a user who is already an affiliate is redirected straight to the dashboard', function () {
    $user = User::factory()->create();
    Affiliate::findOrCreateForUser($user);
    $this->actingAs($user);

    Volt::test('pages.account.affiliate-apply')
        ->assertRedirect(route('account.affiliate'));
});

test('submitting with a reason that is too short fails validation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('pages.account.affiliate-apply')
        ->set('whatsapp', '+2348000000000')
        ->set('reason', 'too short')
        ->call('submit')
        ->assertHasErrors(['reason']);

    expect(AffiliateApplication::where('user_id', $user->id)->exists())->toBeFalse();
});
