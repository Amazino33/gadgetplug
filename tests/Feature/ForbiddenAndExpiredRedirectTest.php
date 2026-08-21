<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// Routes that throw the way a real refused page does, so the handlers are
// exercised through the HTTP stack rather than called directly.
beforeEach(function () {
    Route::middleware('web')->get('/__forbidden', fn () => abort(403));
    Route::middleware('web')->get('/__denied', function () {
        throw new AuthorizationException('Nope.');
    });
    Route::middleware('web')->get('/__expired', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    });
});

test('a guest hitting a forbidden page is sent to the login form, not a dead end', function () {
    $this->get('/__forbidden')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
});

test('the page they were refused is remembered, so login lands them back on it', function () {
    $this->get('/__forbidden')->assertRedirect(route('login'));

    expect(session('url.intended'))->toContain('/__forbidden');
});

test('a guest opening the plug panel is sent to login rather than a bare 403', function () {
    $response = $this->get('/plug');

    $response->assertRedirect(route('login'));

    expect(session('url.intended'))->toContain('/plug');
});

test('an AuthorizationException redirects the same way a 403 abort does', function () {
    $this->get('/__denied')->assertRedirect(route('login'));
});

test('a signed-in customer refused the plug panel lands on their account, not a loop', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->get('/plug')
        ->assertRedirect(route('account.profile'));
});

test('an expired session redirects to login with an explanation instead of Page Expired', function () {
    $this->get('/__expired')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
});

test('a 419 on a POST does not remember the url, so the submission is not replayed', function () {
    Route::middleware('web')->post('/__expired-post', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    });

    $this->post('/__expired-post')->assertRedirect(route('login'));

    expect(session('url.intended'))->toBeNull();
});

test('an API caller still gets a real 403 rather than a redirect it cannot follow', function () {
    $this->getJson('/__forbidden')->assertStatus(403);
});

test('a Livewire request still gets a real 403', function () {
    $this->withHeaders(['X-Livewire' => '1'])->get('/__forbidden')->assertStatus(403);
});

test('an ordinary 404 is left alone', function () {
    $this->get('/__definitely-not-a-route')->assertStatus(404);
});

test('a vendor owner still reaches their own plug panel', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Redirect Test Store']);

    $response = $this->actingAs($owner)->get('/plug');

    // Whatever the panel decides, it must not be a dead-end 403.
    expect($response->status())->not->toBe(403);
});
