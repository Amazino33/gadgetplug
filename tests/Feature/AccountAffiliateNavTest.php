<?php

use App\Models\Affiliate;
use App\Models\User;

test('a non-affiliate sees Become an Affiliate in their account nav, not the affiliate dashboard link', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/account')
        ->assertSee('Become an Affiliate')
        ->assertDontSee('Become a Plug');
});

test('an existing affiliate sees the Affiliate dashboard link instead of Become an Affiliate', function () {
    $user = User::factory()->create();
    Affiliate::findOrCreateForUser($user);
    $this->actingAs($user);

    $response = $this->get('/account');

    $response->assertSee('Affiliate')
        ->assertDontSee('Become an Affiliate')
        ->assertDontSee('Become a Plug');
});
