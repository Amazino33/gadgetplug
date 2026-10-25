<?php

use App\Models\User;

test('a guest visiting affiliate join sees the choice screen, while an authenticated user is redirected straight to the form', function () {
    // Guest visits /affiliate/join
    $response = $this->get(route('affiliate.join'));
    $response->assertOk();
    $response->assertSee('Earn with GadgetPlug — up to ₦10,000/week. No fees, no capital.');
    $response->assertSee("I'm new — Create account", false);
    $response->assertSee('I already have an account — Log in');
    expect(session('url.intended'))->toBe(route('account.affiliate-apply', absolute: false));

    // Authenticated user visits /affiliate/join
    $user = User::factory()->create();
    $authResponse = $this->actingAs($user)->get(route('affiliate.join'));
    $authResponse->assertRedirect(route('account.affiliate-apply'));
});

test('a guest visiting affiliate join and then logging in lands on the affiliate registration page', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    // Guest visits join page which sets intended destination
    $this->get(route('affiliate.join'));

    // Log in
    $response = $this->post(route('login.store'), [
        'email'    => $user->email,
        'password' => 'password123',
    ]);

    $response->assertRedirect(route('account.affiliate-apply'));
});

test('a guest visiting affiliate join and then registering lands on the affiliate registration page', function () {
    // Guest visits join page which sets intended destination
    $this->get(route('affiliate.join'));

    // Register
    $response = $this->post(route('register.store'), [
        'name'                  => 'Affiliate Applicant',
        'email'                 => 'newaffiliate@example.com',
        'password'              => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect(route('account.affiliate-apply'));
});
