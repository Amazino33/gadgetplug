<?php

use App\Models\Affiliate;
use App\Models\AffiliateApplication;
use App\Models\User;
use Livewire\Volt\Volt;

test('a user with no application sees the form and can submit one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('pages.account.affiliate-apply')
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('platforms', ['whatsapp', 'instagram'])
        ->set('audience_size', '100-500')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->set('consent', true)
        ->call('submit')
        ->assertSet('submitted', true);

    $application = AffiliateApplication::where('user_id', $user->id)->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('pending')
        ->and($application->whatsapp)->toBe('+2348000000000')
        ->and($application->category)->toBe('student')
        ->and($application->school)->toBe('UniUyo')
        ->and($application->platforms)->toBe(['whatsapp', 'instagram'])
        ->and($application->audience_size)->toBe('100-500')
        ->and($application->consent)->toBeTrue()
        ->and($application->consented_at)->not->toBeNull();
});

test('submission without consent or without a platform fails validation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Missing consent
    Volt::test('pages.account.affiliate-apply')
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('platforms', ['whatsapp'])
        ->set('audience_size', '100-500')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->set('consent', false)
        ->call('submit')
        ->assertHasErrors(['consent']);

    // Missing platforms
    Volt::test('pages.account.affiliate-apply')
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('platforms', [])
        ->set('audience_size', '100-500')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->set('consent', true)
        ->call('submit')
        ->assertHasErrors(['platforms']);

    expect(AffiliateApplication::where('user_id', $user->id)->exists())->toBeFalse();
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
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('platforms', ['whatsapp'])
        ->set('audience_size', '100-500')
        ->set('reason', 'too short')
        ->set('consent', true)
        ->call('submit')
        ->assertHasErrors(['reason']);

    expect(AffiliateApplication::where('user_id', $user->id)->exists())->toBeFalse();
});
