<?php

use App\Models\Affiliate;
use App\Models\AffiliateApplication;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Livewire\Volt\Volt;

test('a user with no application sees the form and can submit one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $state = State::first() ?? State::create(['name' => 'Akwa Ibom']);
    $lga   = Lga::where('state_id', $state->id)->first() ?? Lga::create(['name' => 'Uyo', 'state_id' => $state->id]);

    Volt::test('pages.account.affiliate-apply')
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('stateId', $state->id)
        ->set('lgaId', $lga->id)
        ->set('address', '123 Ikot Ekpene Road, Uyo')
        ->set('platforms', ['whatsapp', 'instagram'])
        ->set('audience_size', '100-500')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->set('consent', true)
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertDispatched('affiliate-application-submitted');

    $application = AffiliateApplication::where('user_id', $user->id)->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('pending')
        ->and($application->whatsapp)->toBe('+2348000000000')
        ->and($application->category)->toBe('student')
        ->and($application->school)->toBe('UniUyo')
        ->and($application->state_id)->toBe($state->id)
        ->and($application->lga_id)->toBe($lga->id)
        ->and($application->address)->toBe('123 Ikot Ekpene Road, Uyo')
        ->and($application->platforms)->toBe(['whatsapp', 'instagram'])
        ->and($application->audience_size)->toBe('100-500')
        ->and($application->consent)->toBeTrue()
        ->and($application->consented_at)->not->toBeNull();
});

test('submission without consent or without a platform fails validation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $state = State::first() ?? State::create(['name' => 'Akwa Ibom']);
    $lga   = Lga::where('state_id', $state->id)->first() ?? Lga::create(['name' => 'Uyo', 'state_id' => $state->id]);

    // Missing consent
    Volt::test('pages.account.affiliate-apply')
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('stateId', $state->id)
        ->set('lgaId', $lga->id)
        ->set('address', '123 Ikot Ekpene Road, Uyo')
        ->set('platforms', ['whatsapp'])
        ->set('audience_size', '100-500')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->set('consent', false)
        ->call('submit')
        ->assertHasErrors(['consent'])
        ->assertNotDispatched('affiliate-application-submitted');

    // Missing platforms
    Volt::test('pages.account.affiliate-apply')
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('stateId', $state->id)
        ->set('lgaId', $lga->id)
        ->set('address', '123 Ikot Ekpene Road, Uyo')
        ->set('platforms', [])
        ->set('audience_size', '100-500')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->set('consent', true)
        ->call('submit')
        ->assertHasErrors(['platforms'])
        ->assertNotDispatched('affiliate-application-submitted');

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
        ->assertDontSee('Submit Application')
        ->assertNotDispatched('affiliate-application-submitted');
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

test('submission without state, lga, or address fails validation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('pages.account.affiliate-apply')
        ->set('category', 'student')
        ->set('school', 'UniUyo')
        ->set('whatsapp', '08000000000')
        ->set('platforms', ['whatsapp'])
        ->set('audience_size', '100-500')
        ->set('reason', 'I run a tech review page on Instagram with a decent following and share deals often.')
        ->set('consent', true)
        ->call('submit')
        ->assertHasErrors(['stateId', 'lgaId', 'address'])
        ->assertNotDispatched('affiliate-application-submitted');

    expect(AffiliateApplication::where('user_id', $user->id)->exists())->toBeFalse();
});
